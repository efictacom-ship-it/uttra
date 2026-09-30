<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Services\PeriodService;
use App\Modules\Catalog\Services\ServiceConsumptionService;
use App\Modules\Finance\Services\PaymentPostingService;
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
 * ترحيل فاتورة البيع محليًا:
 * قفل المستند ← فحص الفترة والتجميد ← قفل الأرصدة بترتيب ثابت ← صرف المخزون بالمتوسط
 * ← استهلاك خامات الخدمات ← تخصيص الرقم ← قيد متوازن ← دفعات مستقلة بقيد لكل دفعة.
 *
 * القيد: مدين (حساب تحكم الكاشير للمدفوع + العميل للباقي) / دائن (الإيراد + الضريبة)،
 * ومدين تكلفة المبيعات / دائن المخزون عند وجود سلع أو خامات.
 */
final class SalesPostingService
{
    public function __construct(
        private readonly DocumentWriter $writer,
        private readonly StockPostingService $stock,
        private readonly StockFreezeGuard $freeze,
        private readonly ServiceConsumptionService $consumption,
        private readonly JournalPostingService $journal,
        private readonly PeriodService $periods,
        private readonly AccountResolver $accounts,
        private readonly DocumentNumberService $numbers,
        private readonly PaymentPostingService $payments,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  list<array{method:string, financial_account_id:int, amount:string, external_reference?:string|null}>  $payments
     */
    public function post(int $documentId, array $payments, int $userId, bool $insideTransaction = false): Document
    {
        $run = fn (): Document => $this->postInternal($documentId, $payments, $userId);

        return $insideTransaction ? $run() : DB::transaction($run, 3);
    }

    private function postInternal(int $documentId, array $paymentRows, int $userId): Document
    {
        $document = $this->writer->lockForPosting($documentId);

        if ($document->kind !== Document::KIND_SALE) {
            throw new DomainException('هذه الخدمة تُرحّل فواتير البيع فقط.');
        }

        $period = $this->periods->openPeriodFor($document->business_date->toDateString());
        $this->freeze->assertNotFrozen($document->warehouse_id);

        $lines = $document->lines()->get();

        if ($lines->isEmpty()) {
            throw new DomainException('لا يمكن إصدار فاتورة بلا سطور.');
        }

        $this->assertHeaderMatchesLines($document, $lines);

        $paid = Money::zero();
        foreach ($paymentRows as $row) {
            $paid = $paid->plus(Money::of($row['amount']));
        }

        $grandTotal = Money::of($document->grand_total);

        if ($paid->isGreaterThan($grandTotal)) {
            throw new DomainException('مجموع الدفعات أكبر من إجمالي الفاتورة.');
        }

        $credit = $grandTotal->minus($paid);

        if ($credit->isPositive() && ! $document->party_id) {
            throw new DomainException('البيع الآجل يتطلب اختيار عميل.');
        }

        // قفل كل أرصدة الأصناف المتأثرة (سلع السطور + خامات الخدمات) بترتيب ثابت
        $affectedItems = $this->affectedItemIds($lines);
        if ($affectedItems !== [] && $document->warehouse_id) {
            $this->stock->lockBalances((int) $document->warehouse_id, $affectedItems);
        }

        $totalCost = Money::zero();
        $revenueLines = [];
        $taxByAccount = Money::zero();

        foreach ($lines as $line) {
            $totalCost = $totalCost->plus($this->applyStockEffect($document, $line));

            $revenueAccountId = $this->revenueAccountFor($line);
            $revenueLines[$revenueAccountId] ??= Money::zero();
            $revenueLines[$revenueAccountId] = $revenueLines[$revenueAccountId]->plus(Money::of($line->net_amount));
            $taxByAccount = $taxByAccount->plus(Money::of($line->tax_amount));
        }

        $number = $document->document_number ?? $this->numbers->next(Document::KIND_SALE, $document->business_date->toDateString());

        $document = $this->writer->markPosted($document, $number, $period->id);
        $this->stock->consumeReservations($document->id);

        $journalLines = [];

        if ($paid->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::POS_SALES_CONTROL),
                'debit' => $paid,
                'description' => 'مبيعات مدفوعة — '.$number,
            ];
        }

        if ($credit->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->receivableAccountFor($document),
                'debit' => $credit,
                'party_id' => $document->party_id,
                'description' => 'بيع آجل — '.$number,
            ];
        }

        foreach ($revenueLines as $accountId => $amount) {
            $journalLines[] = ['account_id' => (int) $accountId, 'credit' => $amount, 'description' => 'إيراد — '.$number];
        }

        if ($taxByAccount->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::VAT_OUTPUT),
                'credit' => $taxByAccount,
                'description' => 'ضريبة مخرجات — '.$number,
            ];
        }

        if ($totalCost->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::COST_OF_GOODS_SOLD),
                'debit' => $totalCost,
                'description' => 'تكلفة المبيعات — '.$number,
            ];
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::INVENTORY),
                'credit' => $totalCost,
                'description' => 'إخراج مخزون — '.$number,
            ];
        }

        $this->journal->post(
            lines: $journalLines,
            entryDate: $document->business_date->toDateString(),
            description: 'فاتورة بيع '.$number,
            documentId: $document->id,
            paymentId: null,
            userId: $userId,
        );

        foreach ($paymentRows as $index => $row) {
            $this->payments->postSaleReceipt(
                document: $document,
                method: $row['method'],
                financialAccountId: (int) $row['financial_account_id'],
                amount: Money::of($row['amount']),
                userId: $userId,
                externalReference: $row['external_reference'] ?? null,
                idempotencyKey: $document->idempotency_key.'-pay-'.$index,
            );
        }

        $this->audit->log('document', $document->id, 'sale_posted', null, [
            'document_number' => $number,
            'grand_total' => $document->grand_total,
            'paid' => $paid->toDb(),
        ]);

        return $document->refresh();
    }

    /** يخرج المخزون للسطر (سلعة) أو لخامات الخدمة، ويعيد التكلفة الإجمالية. */
    private function applyStockEffect(Document $document, DocumentLine $line): Money
    {
        $cost = Money::zero();

        if (! $document->warehouse_id) {
            return $cost;
        }

        if ($line->item_type_snapshot === 'stock' && $line->item_id) {
            $movement = $this->stock->issue(
                warehouseId: (int) $document->warehouse_id,
                itemId: (int) $line->item_id,
                baseQuantity: Money::of($line->base_quantity),
                movementType: 'sale',
                documentId: $document->id,
                documentLineId: $line->id,
                componentNumber: 0,
                ignoreReservations: true,
            );

            $lineCost = Money::of($movement->value_delta)->abs();
            $line->update([
                'unit_cost_snapshot' => Money::of($movement->unit_cost)->toDb(),
                'total_cost_snapshot' => $lineCost->toDb(),
            ]);

            return $lineCost;
        }

        if ($line->item_type_snapshot === 'service' && $line->item_unit_id) {
            $components = $this->consumption->consumptionFor((int) $line->item_unit_id, Money::of($line->quantity));

            foreach ($components as $index => $component) {
                $movement = $this->stock->issue(
                    warehouseId: (int) $document->warehouse_id,
                    itemId: $component['material_item_id'],
                    baseQuantity: $component['base_quantity'],
                    movementType: 'material_usage',
                    documentId: $document->id,
                    documentLineId: $line->id,
                    componentNumber: $index + 1,
                    ignoreReservations: true,
                );

                $cost = $cost->plus(Money::of($movement->value_delta)->abs());
            }

            if ($cost->isPositive()) {
                $line->update(['total_cost_snapshot' => $cost->toDb()]);
            }
        }

        return $cost;
    }

    /** @return list<int> */
    private function affectedItemIds($lines): array
    {
        $ids = [];

        foreach ($lines as $line) {
            if ($line->item_type_snapshot === 'stock' && $line->item_id) {
                $ids[] = (int) $line->item_id;
            }

            if ($line->item_type_snapshot === 'service' && $line->item_unit_id) {
                foreach ($this->consumption->consumptionFor((int) $line->item_unit_id, Money::of('1')) as $component) {
                    $ids[] = $component['material_item_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function revenueAccountFor(DocumentLine $line): int
    {
        if ($line->item_id && $line->item?->revenue_account_id) {
            return (int) $line->item->revenue_account_id;
        }

        return $line->item_type_snapshot === 'service'
            ? $this->accounts->id(AccountResolver::SERVICE_REVENUE)
            : $this->accounts->id(AccountResolver::SALES_REVENUE);
    }

    private function receivableAccountFor(Document $document): int
    {
        $party = $document->party_id ? Party::query()->find($document->party_id) : null;

        return (int) ($party?->receivable_account_id ?? $this->accounts->id(AccountResolver::ACCOUNTS_RECEIVABLE));
    }

    /** الرأس يجب أن يطابق مجموع السطور بالضبط قبل الترحيل. */
    private function assertHeaderMatchesLines(Document $document, $lines): void
    {
        $net = Money::zero();
        $tax = Money::zero();

        foreach ($lines as $line) {
            $net = $net->plus(Money::of($line->net_amount));
            $tax = $tax->plus(Money::of($line->tax_amount));
        }

        if (! $net->equals(Money::of($document->net_total)) || ! $tax->equals(Money::of($document->tax_total))) {
            throw new DomainException('إجماليات المستند لا تطابق سطوره؛ أعد حساب الفاتورة قبل الترحيل.');
        }

        $grand = $net->plus($tax);

        if (! $grand->equals(Money::of($document->grand_total))) {
            throw new DomainException('الإجمالي النهائي لا يساوي الصافي زائد الضريبة.');
        }
    }
}
