<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Services\PeriodService;
use App\Modules\Inventory\Services\StockFreezeGuard;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Parties\Models\Party;
use App\Modules\Settings\Models\BusinessSetting;
use App\Shared\Audit\AuditService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Models\DocumentLine;
use App\Shared\Documents\Services\DocumentNumberService;
use App\Shared\Documents\Services\DocumentWriter;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل مرتجع الشراء: عكس استلام البضاعة وتخفيض مستحقات المورد.
 *
 * المخزون يُصرف بتكلفة سطر الشراء الأصلي (unit_cost_snapshot) وليس بمتوسط التكلفة
 * الحالي: البضاعة العائدة هي نفسها التي دخلت بتلك التكلفة، فإخراجها بالمتوسط الحالي
 * (الذي قد يكون تغيّر بمشتريات لاحقة) يُدخل ربحًا أو خسارة وهمية على حساب المخزون
 * لا علاقة لها بعملية الإرجاع نفسها.
 *
 * الفرق بين القيمة التجارية للمرتجع (الكمية × سعر الإرجاع المتفق عليه مع المورد)
 * وقيمته بتكلفة الشراء الأصلية يُقفل على حساب "فرق تكلفة مرتجع الشراء" المستقل،
 * حتى لا يُحمَّل خطأً على حساب المخزون (الذي يجب أن يعكس التكلفة الفعلية فقط)
 * ولا على ضريبة المدخلات (التي تُعكس بقيمتها الحقيقية فقط).
 *
 * القيد: مدين المورد (تخفيض المستحق بكامل قيمة المرتجع شاملة الضريبة)،
 *        دائن المخزون بتكلفة السطر الأصلي، دائن ضريبة المدخلات القابلة للخصم
 *        (عكس ما استُرد سابقًا)، ومدين/دائن فرق تكلفة المرتجع حسب اتجاه الفرق.
 */
final class PurchaseReturnPostingService
{
    public function __construct(
        private readonly DocumentWriter $writer,
        private readonly StockPostingService $stock,
        private readonly StockFreezeGuard $freeze,
        private readonly JournalPostingService $journal,
        private readonly PeriodService $periods,
        private readonly AccountResolver $accounts,
        private readonly DocumentNumberService $numbers,
        private readonly AuditService $audit,
    ) {}

    public function post(int $documentId, int $userId, bool $insideTransaction = false): Document
    {
        $run = fn (): Document => $this->postInternal($documentId, $userId);

        return $insideTransaction ? $run() : DB::transaction($run, 3);
    }

    private function postInternal(int $documentId, int $userId): Document
    {
        $document = $this->writer->lockForPosting($documentId);

        if ($document->kind !== Document::KIND_PURCHASE_RETURN) {
            throw new DomainException('هذه الخدمة تُرحّل مرتجعات الشراء فقط.');
        }

        if (! $document->party_id || ! $document->warehouse_id) {
            throw new DomainException('مرتجع الشراء يتطلب موردًا ومخزنًا.');
        }

        if (! $document->original_document_id) {
            throw new DomainException('مرتجع الشراء يجب أن يرتبط بفاتورة شراء أصلية.');
        }

        $period = $this->periods->openPeriodFor($document->business_date->toDateString());
        $this->freeze->assertNotFrozen($document->warehouse_id);

        $lines = $document->lines()->get();

        if ($lines->isEmpty()) {
            throw new DomainException('لا يمكن ترحيل مرتجع شراء بلا سطور.');
        }

        $itemIds = $lines->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->all();
        $this->stock->lockBalances((int) $document->warehouse_id, $itemIds);

        $vatRegistered = (bool) BusinessSetting::current()->vat_registered;

        $inventoryCost = Money::zero();
        $recoverableTax = Money::zero();
        $nonRecoverableTax = Money::zero();
        $commercialNet = Money::zero();

        foreach ($lines as $line) {
            if ($line->item_type_snapshot !== 'stock' || ! $line->item_id || ! $line->original_line_id) {
                throw new DomainException('كل سطر في مرتجع الشراء يجب أن يكون صنفًا مخزنيًا ويشير إلى سطر أصلي من فاتورة الشراء.');
            }

            $originalLine = DocumentLine::query()->find($line->original_line_id);

            if (! $originalLine || (int) $originalLine->document_id !== (int) $document->original_document_id) {
                throw new DomainException('سطر المرتجع لا يطابق سطرًا في فاتورة الشراء الأصلية.');
            }

            if ($originalLine->unit_cost_snapshot === null) {
                throw new DomainException('السطر الأصلي بلا تكلفة موثقة؛ لا يمكن ترحيل المرتجع.');
            }

            $net = Money::of($line->net_amount);
            $tax = Money::of($line->tax_amount);
            $recoverable = $vatRegistered && $line->tax_category_snapshot === 'S' ? $tax : Money::zero();

            $line->update(['tax_recoverable_amount' => $recoverable->toDb()]);

            $recoverableTax = $recoverableTax->plus($recoverable);
            $nonRecoverableTax = $nonRecoverableTax->plus($tax->minus($recoverable));
            $commercialNet = $commercialNet->plus($net);

            $issuedCost = $this->returnStockAtOriginalCost($document, $line, $originalLine);
            $inventoryCost = $inventoryCost->plus($issuedCost);
        }

        $number = $document->document_number ?? $this->numbers->next(Document::KIND_PURCHASE_RETURN, $document->business_date->toDateString());
        $document = $this->writer->markPosted($document, $number, $period->id);

        $journalLines = [];

        $journalLines[] = [
            'account_id' => $this->payableAccountFor($document),
            'debit' => Money::of($document->grand_total),
            'party_id' => $document->party_id,
            'description' => 'تخفيض مستحق المورد — مرتجع شراء '.$number,
        ];

        if ($inventoryCost->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::INVENTORY),
                'credit' => $inventoryCost,
                'description' => 'إخراج مخزون بتكلفة الشراء الأصلية — مرتجع شراء '.$number,
            ];
        }

        if ($recoverableTax->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::VAT_INPUT_RECOVERABLE),
                'credit' => $recoverableTax,
                'description' => 'عكس ضريبة مدخلات — '.$number,
            ];
        }

        // الفرق = (القيمة التجارية للمرتجع + الضريبة غير القابلة للخصم) − (قيمته بتكلفة الشراء الأصلية).
        // هذا يجعل القيد متوازنًا دائمًا رياضيًا: مدين المورد = دائن المخزون + دائن الضريبة + الفرق.
        $difference = $commercialNet->plus($nonRecoverableTax)->minus($inventoryCost);

        if (! $difference->isZero()) {
            $journalLines[] = $difference->isPositive()
                ? [
                    'account_id' => $this->accounts->id(AccountResolver::PURCHASE_RETURN_COST_DIFFERENCE),
                    'credit' => $difference,
                    'description' => 'فرق تكلفة مرتجع شراء — '.$number,
                ]
                : [
                    'account_id' => $this->accounts->id(AccountResolver::PURCHASE_RETURN_COST_DIFFERENCE),
                    'debit' => $difference->abs(),
                    'description' => 'فرق تكلفة مرتجع شراء — '.$number,
                ];
        }

        $this->journal->post(
            lines: $journalLines,
            entryDate: $document->business_date->toDateString(),
            description: 'مرتجع شراء '.$number,
            documentId: $document->id,
            paymentId: null,
            userId: $userId,
        );

        $this->audit->log('document', $document->id, 'purchase_return_posted', null, [
            'document_number' => $number,
            'grand_total' => $document->grand_total,
            'original_document_id' => $document->original_document_id,
        ]);

        return $document->refresh();
    }

    /** يصرف المخزون قسرًا بتكلفة سطر الشراء الأصلي، لا بمتوسط التكلفة الحالي. */
    private function returnStockAtOriginalCost(Document $document, DocumentLine $line, DocumentLine $originalLine): Money
    {
        $baseQuantity = Money::of($line->base_quantity);
        $originalUnitCost = Money::of($originalLine->unit_cost_snapshot);

        $movement = $this->stock->issue(
            warehouseId: (int) $document->warehouse_id,
            itemId: (int) $line->item_id,
            baseQuantity: $baseQuantity,
            movementType: 'purchase_return',
            documentId: $document->id,
            documentLineId: $line->id,
            forcedUnitCost: $originalUnitCost,
            ignoreReservations: true,
        );

        $issuedCost = Money::of($movement->value_delta)->abs();

        $line->update([
            'unit_cost_snapshot' => $originalUnitCost->toDb(),
            'total_cost_snapshot' => $issuedCost->toDb(),
        ]);

        return $issuedCost;
    }

    private function payableAccountFor(Document $document): int
    {
        $party = Party::query()->find($document->party_id);

        return (int) ($party?->payable_account_id ?? $this->accounts->id(AccountResolver::ACCOUNTS_PAYABLE));
    }
}
