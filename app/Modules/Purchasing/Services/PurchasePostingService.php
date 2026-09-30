<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Services\PeriodService;
use App\Modules\Catalog\Models\Item;
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
 * ترحيل فاتورة الشراء. في النسخة الأولى: اعتماد الفاتورة = استلام البضاعة للمخزن.
 * القيد: مدين المخزون (للسلع) ومدين المصروف (للخدمات والمصروفات)
 *        ومدين ضريبة المدخلات القابلة للخصم / دائن المورد.
 * فاتورة المورد تُحفظ وتُعالج محاسبيًا فقط، ولا تُرسل إلكترونيًا نيابةً عنه.
 */
final class PurchasePostingService
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

        if ($document->kind !== Document::KIND_PURCHASE) {
            throw new DomainException('هذه الخدمة تُرحّل فواتير الشراء فقط.');
        }

        if (! $document->party_id) {
            throw new DomainException('فاتورة الشراء تتطلب موردًا.');
        }

        $period = $this->periods->openPeriodFor($document->business_date->toDateString());
        $this->freeze->assertNotFrozen($document->warehouse_id);

        $lines = $document->lines()->get();

        if ($lines->isEmpty()) {
            throw new DomainException('لا يمكن ترحيل فاتورة شراء بلا سطور.');
        }

        $vatRegistered = (bool) BusinessSetting::current()->vat_registered;

        $stockItemIds = $lines->where('item_type_snapshot', 'stock')->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->all();

        if ($stockItemIds !== [] && $document->warehouse_id) {
            $this->stock->lockBalances((int) $document->warehouse_id, $stockItemIds);
        }

        $inventoryDebit = Money::zero();
        $expenseDebits = [];
        $recoverableTax = Money::zero();
        $nonRecoverableTax = Money::zero();

        foreach ($lines as $line) {
            $net = Money::of($line->net_amount);
            $tax = Money::of($line->tax_amount);
            $recoverable = $vatRegistered && $line->tax_category_snapshot === 'S' ? $tax : Money::zero();

            $line->update(['tax_recoverable_amount' => $recoverable->toDb()]);

            $recoverableTax = $recoverableTax->plus($recoverable);
            $nonRecoverable = $tax->minus($recoverable);
            $nonRecoverableTax = $nonRecoverableTax->plus($nonRecoverable);

            // الضريبة غير القابلة للخصم تُضاف إلى تكلفة الصنف أو المصروف
            $costBase = $net->plus($nonRecoverable);

            if ($line->item_type_snapshot === 'stock' && $line->item_id && $document->warehouse_id) {
                $this->receiveStock($document, $line, $costBase);
                $inventoryDebit = $inventoryDebit->plus($costBase);

                continue;
            }

            $accountId = $this->expenseAccountFor($line);
            $expenseDebits[$accountId] ??= Money::zero();
            $expenseDebits[$accountId] = $expenseDebits[$accountId]->plus($costBase);
        }

        $number = $document->document_number ?? $this->numbers->next(Document::KIND_PURCHASE, $document->business_date->toDateString());
        $document = $this->writer->markPosted($document, $number, $period->id);

        $journalLines = [];

        if ($inventoryDebit->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::INVENTORY),
                'debit' => $inventoryDebit,
                'description' => 'استلام مخزون — '.$number,
            ];
        }

        foreach ($expenseDebits as $accountId => $amount) {
            $journalLines[] = ['account_id' => (int) $accountId, 'debit' => $amount, 'description' => 'شراء خدمة/مصروف — '.$number];
        }

        if ($recoverableTax->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::VAT_INPUT_RECOVERABLE),
                'debit' => $recoverableTax,
                'description' => 'ضريبة مدخلات — '.$number,
            ];
        }

        $journalLines[] = [
            'account_id' => $this->payableAccountFor($document),
            'credit' => Money::of($document->grand_total),
            'party_id' => $document->party_id,
            'description' => 'مستحق للمورد — '.$number,
        ];

        $this->journal->post(
            lines: $journalLines,
            entryDate: $document->business_date->toDateString(),
            description: 'فاتورة شراء '.$number,
            documentId: $document->id,
            paymentId: null,
            userId: $userId,
        );

        $this->audit->log('document', $document->id, 'purchase_posted', null, [
            'document_number' => $number,
            'grand_total' => $document->grand_total,
        ]);

        return $document->refresh();
    }

    private function receiveStock(Document $document, DocumentLine $line, Money $costBase): void
    {
        $baseQuantity = Money::of($line->base_quantity);

        $movement = $this->stock->receive(
            warehouseId: (int) $document->warehouse_id,
            itemId: (int) $line->item_id,
            baseQuantity: $baseQuantity,
            totalCost: $costBase,
            movementType: 'purchase',
            documentId: $document->id,
            documentLineId: $line->id,
        );

        $unitCost = Money::of($movement->unit_cost);

        $line->update([
            'unit_cost_snapshot' => $unitCost->toDb(),
            'total_cost_snapshot' => $costBase->toDb(),
        ]);

        // آخر تكلفة شراء ليست متوسط التكلفة: تُحفظ للاسترشاد فقط
        Item::query()->whereKey($line->item_id)->update(['last_purchase_cost' => $unitCost->toDb()]);
    }

    private function expenseAccountFor(DocumentLine $line): int
    {
        if ($line->account_id) {
            return (int) $line->account_id;
        }

        if ($line->item_id && $line->item?->cost_account_id) {
            return (int) $line->item->cost_account_id;
        }

        return $this->accounts->id(AccountResolver::MATERIAL_CONSUMPTION);
    }

    private function payableAccountFor(Document $document): int
    {
        $party = Party::query()->find($document->party_id);

        return (int) ($party?->payable_account_id ?? $this->accounts->id(AccountResolver::ACCOUNTS_PAYABLE));
    }
}
