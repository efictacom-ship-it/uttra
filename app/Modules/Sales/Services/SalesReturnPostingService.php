<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Services\PeriodService;
use App\Modules\Finance\Services\PaymentPostingService;
use App\Modules\Inventory\Services\StockFreezeGuard;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Parties\Models\Party;
use App\Shared\Audit\AuditService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Models\DocumentLine;
use App\Shared\Documents\Services\DocumentNumberService;
use App\Shared\Documents\Services\DocumentWriter;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل مرتجع البيع: عكس الإيراد والضريبة بقيمة سطر المرتجع كما أُدخلت، وإعادة
 * المخزون بتكلفة سطر البيع الأصلي (unit_cost_snapshot) لا بالمتوسط الحالي — حتى
 * تُعكس تكلفة المبيعات (COGS) بنفس القيمة التي خرجت بها تمامًا. مجموعتان متوازنتان
 * منفصلتان في نفس القيد: (إيراد+ضريبة مقابل تحكم الكاشير/العميل) و(مخزون مقابل
 * تكلفة المبيعات)، فلا حاجة لحساب فرق كما في مرتجع الشراء.
 */
final class SalesReturnPostingService
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

        if ($document->kind !== Document::KIND_SALE_RETURN) {
            throw new DomainException('هذه الخدمة تُرحّل مرتجعات البيع فقط.');
        }

        if (! $document->warehouse_id) {
            throw new DomainException('مرتجع البيع يتطلب مخزنًا.');
        }

        if (! $document->original_document_id) {
            throw new DomainException('مرتجع البيع يجب أن يرتبط بفاتورة بيع أصلية.');
        }

        $period = $this->periods->openPeriodFor($document->business_date->toDateString());
        $this->freeze->assertNotFrozen($document->warehouse_id);

        $lines = $document->lines()->get();

        if ($lines->isEmpty()) {
            throw new DomainException('لا يمكن ترحيل مرتجع بيع بلا سطور.');
        }

        $itemIds = $lines->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->all();
        $this->stock->lockBalances((int) $document->warehouse_id, $itemIds);

        $inventoryCost = Money::zero();
        $revenueDebits = [];
        $taxDebit = Money::zero();

        foreach ($lines as $line) {
            if ($line->item_type_snapshot !== 'stock' || ! $line->item_id || ! $line->original_line_id) {
                throw new DomainException('كل سطر في مرتجع البيع يجب أن يكون صنفًا مخزنيًا ويشير إلى سطر أصلي من فاتورة البيع.');
            }

            $originalLine = DocumentLine::query()->find($line->original_line_id);

            if (! $originalLine || (int) $originalLine->document_id !== (int) $document->original_document_id) {
                throw new DomainException('سطر المرتجع لا يطابق سطرًا في فاتورة البيع الأصلية.');
            }

            if ($originalLine->unit_cost_snapshot === null) {
                throw new DomainException('السطر الأصلي بلا تكلفة موثقة؛ لا يمكن ترحيل المرتجع.');
            }

            $inventoryCost = $inventoryCost->plus($this->receiveStockAtOriginalCost($document, $line, $originalLine));

            $revenueAccountId = $this->revenueAccountFor($line);
            $revenueDebits[$revenueAccountId] ??= Money::zero();
            $revenueDebits[$revenueAccountId] = $revenueDebits[$revenueAccountId]->plus(Money::of($line->net_amount));

            $taxDebit = $taxDebit->plus(Money::of($line->tax_amount));
        }

        $number = $document->document_number ?? $this->numbers->next(Document::KIND_SALE_RETURN, $document->business_date->toDateString());
        $document = $this->writer->markPosted($document, $number, $period->id);

        $journalLines = [];

        foreach ($revenueDebits as $accountId => $amount) {
            $journalLines[] = ['account_id' => (int) $accountId, 'debit' => $amount, 'description' => 'عكس إيراد — مرتجع بيع '.$number];
        }

        if ($taxDebit->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::VAT_OUTPUT),
                'debit' => $taxDebit,
                'description' => 'عكس ضريبة مخرجات — '.$number,
            ];
        }

        $journalLines[] = [
            'account_id' => $this->creditAccountFor($document),
            'credit' => Money::of($document->grand_total),
            'party_id' => $document->party_id,
            'description' => 'مستحق للعميل — مرتجع بيع '.$number,
        ];

        if ($inventoryCost->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::INVENTORY),
                'debit' => $inventoryCost,
                'description' => 'استلام مخزون بتكلفة البيع الأصلية — مرتجع بيع '.$number,
            ];
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::COST_OF_GOODS_SOLD),
                'credit' => $inventoryCost,
                'description' => 'عكس تكلفة المبيعات — '.$number,
            ];
        }

        $this->journal->post(
            lines: $journalLines,
            entryDate: $document->business_date->toDateString(),
            description: 'مرتجع بيع '.$number,
            documentId: $document->id,
            paymentId: null,
            userId: $userId,
        );

        $this->audit->log('document', $document->id, 'sale_return_posted', null, [
            'document_number' => $number,
            'grand_total' => $document->grand_total,
        ]);

        return $document->refresh();
    }

    /** يستقبل المخزون بتكلفة سطر البيع الأصلي، لا بالمتوسط الحالي. */
    private function receiveStockAtOriginalCost(Document $document, DocumentLine $line, DocumentLine $originalLine): Money
    {
        $baseQuantity = Money::of($line->base_quantity);
        $originalUnitCost = Money::of($originalLine->unit_cost_snapshot);
        $totalCost = $originalUnitCost->multipliedBy($baseQuantity);

        $movement = $this->stock->receive(
            warehouseId: (int) $document->warehouse_id,
            itemId: (int) $line->item_id,
            baseQuantity: $baseQuantity,
            totalCost: $totalCost,
            movementType: 'sale_return',
            documentId: $document->id,
            documentLineId: $line->id,
        );

        $line->update([
            'unit_cost_snapshot' => Money::of($movement->unit_cost)->toDb(),
            'total_cost_snapshot' => $totalCost->toDb(),
        ]);

        return $totalCost;
    }

    private function revenueAccountFor(DocumentLine $line): int
    {
        if ($line->item_id && $line->item?->revenue_account_id) {
            return (int) $line->item->revenue_account_id;
        }

        return $this->accounts->id(AccountResolver::SALES_REVENUE);
    }

    private function creditAccountFor(Document $document): int
    {
        $party = $document->party_id ? Party::query()->find($document->party_id) : null;

        return (int) ($party?->receivable_account_id ?? $this->accounts->id(AccountResolver::ACCOUNTS_RECEIVABLE));
    }
}
