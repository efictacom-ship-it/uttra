<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Services\PeriodService;
use App\Shared\Audit\AuditService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Services\DocumentNumberService;
use App\Shared\Documents\Services\DocumentWriter;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * مستندات المخزون غير التجارية: الرصيد الافتتاحي، التلف والتسويات، الاستهلاك اليدوي.
 * لا ضريبة ولا إيراد فيها، والقيد يقابل المخزون بحساب الافتتاحي أو التلف أو الفرق.
 */
final class StockDocumentService
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

    public function post(int $documentId, int $userId, bool $insideTransaction = false, bool $allowDuringFreeze = false): Document
    {
        $run = fn (): Document => $this->postInternal($documentId, $userId, $allowDuringFreeze);

        return $insideTransaction ? $run() : DB::transaction($run, 3);
    }

    private function postInternal(int $documentId, int $userId, bool $allowDuringFreeze): Document
    {
        $document = $this->writer->lockForPosting($documentId);

        $kinds = [Document::KIND_STOCK_OPENING, Document::KIND_STOCK_ADJUSTMENT, Document::KIND_MATERIAL_USAGE];

        if (! in_array($document->kind, $kinds, true)) {
            throw new DomainException('هذه الخدمة تُرحّل مستندات المخزون فقط.');
        }

        if (! $document->warehouse_id) {
            throw new DomainException('مستند المخزون يحتاج مخزنًا.');
        }

        $period = $this->periods->openPeriodFor($document->business_date->toDateString());

        if (! $allowDuringFreeze) {
            $this->freeze->assertNotFrozen($document->warehouse_id);
        }

        $lines = $document->lines()->get();

        if ($lines->isEmpty()) {
            throw new DomainException('لا يمكن ترحيل مستند مخزون بلا سطور.');
        }

        $this->stock->lockBalances((int) $document->warehouse_id, $lines->pluck('item_id')->filter()->all());

        $inDelta = Money::zero();
        $outDelta = Money::zero();

        foreach ($lines as $line) {
            if ($line->item_type_snapshot !== 'stock' || ! $line->item_id) {
                throw new DomainException('مستند المخزون يقبل أصناف المخزون فقط؛ الخدمة لا تملك رصيدًا.');
            }

            $baseQuantity = Money::of($line->base_quantity);

            if ($line->stock_direction === 'in') {
                $cost = Money::of($line->unit_cost_snapshot ?? '0')->multipliedBy($baseQuantity);

                $movement = $this->stock->receive(
                    warehouseId: (int) $document->warehouse_id,
                    itemId: (int) $line->item_id,
                    baseQuantity: $baseQuantity,
                    totalCost: $cost,
                    movementType: $document->kind === Document::KIND_STOCK_OPENING ? 'opening' : 'adjustment',
                    documentId: $document->id,
                    documentLineId: $line->id,
                );

                $line->update(['total_cost_snapshot' => Money::of($movement->value_delta)->toDb()]);
                $inDelta = $inDelta->plus(Money::of($movement->value_delta));

                continue;
            }

            $movement = $this->stock->issue(
                warehouseId: (int) $document->warehouse_id,
                itemId: (int) $line->item_id,
                baseQuantity: $baseQuantity,
                movementType: $document->kind === Document::KIND_MATERIAL_USAGE ? 'material_usage' : 'adjustment',
                documentId: $document->id,
                documentLineId: $line->id,
                ignoreReservations: $document->kind !== Document::KIND_MATERIAL_USAGE,
            );

            $lineCost = Money::of($movement->value_delta)->abs();

            $line->update([
                'unit_cost_snapshot' => Money::of($movement->unit_cost)->toDb(),
                'total_cost_snapshot' => $lineCost->toDb(),
            ]);

            $outDelta = $outDelta->plus($lineCost);
        }

        $number = $document->document_number ?? $this->numbers->next($document->kind, $document->business_date->toDateString());
        $document = $this->writer->markPosted($document, $number, $period->id);

        $counterAccountId = match ($document->kind) {
            Document::KIND_STOCK_OPENING => $this->accounts->id(AccountResolver::OPENING_BALANCE),
            Document::KIND_MATERIAL_USAGE => $this->accounts->id(AccountResolver::MATERIAL_CONSUMPTION),
            default => $this->accounts->id($document->reason === 'stock_count'
                ? AccountResolver::STOCK_COUNT_DIFFERENCE
                : AccountResolver::INVENTORY_SHRINKAGE),
        };

        $inventoryAccountId = $this->accounts->id(AccountResolver::INVENTORY);
        $net = $inDelta->minus($outDelta);

        if (! $net->isZero()) {
            $journalLines = $net->isPositive()
                ? [
                    ['account_id' => $inventoryAccountId, 'debit' => $net, 'description' => 'زيادة مخزون — '.$number],
                    ['account_id' => $counterAccountId, 'credit' => $net, 'description' => 'مقابل حركة المخزون — '.$number],
                ]
                : [
                    ['account_id' => $counterAccountId, 'debit' => $net->abs(), 'description' => 'مقابل حركة المخزون — '.$number],
                    ['account_id' => $inventoryAccountId, 'credit' => $net->abs(), 'description' => 'نقص مخزون — '.$number],
                ];

            $this->journal->post(
                lines: $journalLines,
                entryDate: $document->business_date->toDateString(),
                description: 'مستند مخزون '.$number,
                documentId: $document->id,
                paymentId: null,
                userId: $userId,
            );
        }

        $this->audit->log('document', $document->id, $document->kind.'_posted', null, [
            'document_number' => $number,
            'net_value' => $net->toDb(),
        ]);

        return $document->refresh();
    }
}
