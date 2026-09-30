<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Shared\Audit\AuditService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Services\DocumentDraftService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * الجرد الدوري: جلسة واحدة نشطة لكل مخزن (مضمونة بعمود active_marker المولّد في
 * stock_counts)، تُجمّد حركة المخزن عبر StockFreezeGuard طوال حالتها draft/counting،
 * وتُقفَل بمستند تسوية واحد يحوي فروق الكميات غير الصفرية فقط عند الاعتماد.
 */
final class StockCountService
{
    public function __construct(
        private readonly DocumentDraftService $drafts,
        private readonly StockDocumentService $stockDocuments,
        private readonly AuditService $audit,
    ) {}

    /**
     * إنشاء جلسة جرد وتصوير الرصيد الحالي (الكمية ومتوسط التكلفة) لكل صنف.
     * قائمة أصناف فارغة تعني جرد كل الأصناف المخزنية النشطة.
     *
     * @param  list<int>  $itemIds
     */
    public function create(int $warehouseId, array $itemIds, string $businessDate, int $userId): StockCount
    {
        if (StockCount::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['draft', 'counting'])->exists()) {
            throw new DomainException('يوجد جرد نشط بالفعل على هذا المخزن؛ أكمله أو ألغِه أولًا.');
        }

        return DB::transaction(function () use ($warehouseId, $itemIds, $businessDate, $userId): StockCount {
            $stockCount = StockCount::query()->create([
                'warehouse_id' => $warehouseId,
                'status' => 'draft',
                'counted_at' => $businessDate,
                'created_by' => $userId,
            ]);

            $items = $itemIds !== []
                ? Item::query()->whereIn('id', $itemIds)->where('item_type', 'stock')->get(['id'])
                : Item::query()->where('item_type', 'stock')->where('is_active', true)->get(['id']);

            if ($items->isEmpty()) {
                throw new DomainException('لا توجد أصناف مخزنية لبدء الجرد بها.');
            }

            $balances = DB::table('stock_balances')->where('warehouse_id', $warehouseId)->pluck('quantity', 'item_id');
            $costs = DB::table('stock_balances')->where('warehouse_id', $warehouseId)->pluck('average_cost', 'item_id');

            $rows = $items->map(fn (Item $item) => [
                'stock_count_id' => $stockCount->id,
                'item_id' => $item->id,
                'expected_quantity' => $balances[$item->id] ?? '0',
                'unit_cost_snapshot' => $costs[$item->id] ?? '0',
            ])->all();

            DB::table('stock_count_lines')->insert($rows);

            $this->audit->log('stock_count', $stockCount->id, 'stock_count_created', null, ['warehouse_id' => $warehouseId]);

            return $stockCount->refresh();
        });
    }

    /**
     * تسجيل الكميات المعدودة فعليًا. أول تسجيل ينقل الحالة تلقائيًا من "مسودة"
     * إلى "قيد الجرد" (المخزن مجمّد في الحالتين على حد سواء، لكن الحالة توثّق
     * أن العد الفعلي بدأ).
     *
     * @param  list<array{item_id:int, counted_quantity:string|null}>  $rows
     */
    public function recordCounts(StockCount $stockCount, array $rows, int $userId): StockCount
    {
        if (! $stockCount->isActive()) {
            throw new DomainException('لا يمكن تعديل جلسة جرد غير نشطة.');
        }

        foreach ($rows as $row) {
            StockCountLine::query()
                ->where('stock_count_id', $stockCount->id)
                ->where('item_id', $row['item_id'])
                ->update(['counted_quantity' => $row['counted_quantity'] ?? null]);
        }

        if ($stockCount->status === 'draft') {
            $stockCount->update(['status' => 'counting']);
        }

        $this->audit->log('stock_count', $stockCount->id, 'stock_count_counts_recorded', null, ['lines' => count($rows)]);

        return $stockCount->refresh();
    }

    /**
     * اعتماد الجرد: فرق كل سطر (المعدود − المتوقع) يُبنى منه سطر تسوية واحد فقط
     * للفروق غير الصفرية، ضمن مستند stock_adjustment واحد بسبب "stock_count" حتى
     * يوجَّه تلقائيًا إلى حساب "فروقات الجرد" (انظر StockDocumentService).
     * allowDuringFreeze صحيح هنا: الجرد نفسه هو سبب التجميد، فلا يُحجب عن نفسه.
     */
    public function post(int $stockCountId, int $userId): StockCount
    {
        return DB::transaction(function () use ($stockCountId, $userId): StockCount {
            $stockCount = StockCount::query()->lockForUpdate()->findOrFail($stockCountId);

            if (! $stockCount->isActive()) {
                throw new DomainException('لا يمكن اعتماد جلسة جرد غير نشطة.');
            }

            $lines = $stockCount->lines()->get();

            if ($lines->isEmpty()) {
                throw new DomainException('لا توجد سطور جرد لاعتمادها.');
            }

            if ($lines->contains(fn (StockCountLine $line) => $line->counted_quantity === null)) {
                throw new DomainException('يجب إدخال الكمية المعدودة لجميع الأصناف قبل اعتماد الجرد.');
            }

            $diffLines = [];

            foreach ($lines as $line) {
                $diff = bcsub((string) $line->counted_quantity, (string) $line->expected_quantity, 6);

                if (bccomp($diff, '0', 6) === 0) {
                    continue;
                }

                $item = Item::query()->findOrFail($line->item_id);
                $baseItemUnitId = (int) $item->units()->where('unit_id', $item->base_unit_id)->value('id');

                if (! $baseItemUnitId) {
                    throw new DomainException("الصنف «{$item->name_ar}» بلا وحدة أساسية مُعرَّفة في item_units.");
                }

                $isSurplus = bccomp($diff, '0', 6) > 0;

                $diffLines[] = [
                    'item_id' => $item->id,
                    'item_unit_id' => $baseItemUnitId,
                    'quantity' => $isSurplus ? $diff : ltrim($diff, '-'),
                    'stock_direction' => $isSurplus ? 'in' : 'out',
                    'unit_cost' => (string) $line->unit_cost_snapshot,
                    'description' => 'فرق جرد — '.$item->name_ar,
                ];
            }

            $adjustmentDocumentId = null;

            if ($diffLines !== []) {
                $draft = $this->drafts->create(Document::KIND_STOCK_ADJUSTMENT, [
                    'warehouse_id' => $stockCount->warehouse_id,
                    'business_date' => now()->toDateString(),
                    'reason' => 'stock_count',
                    'lines' => $diffLines,
                ], $userId);

                $adjusted = $this->stockDocuments->post($draft->id, $userId, allowDuringFreeze: true);
                $adjustmentDocumentId = $adjusted->id;
            }

            $stockCount->update([
                'status' => 'posted',
                'adjustment_document_id' => $adjustmentDocumentId,
            ]);

            $this->audit->log('stock_count', $stockCount->id, 'stock_count_posted', null, [
                'adjustment_document_id' => $adjustmentDocumentId,
            ]);

            return $stockCount->refresh();
        });
    }

    /** إلغاء جلسة جرد: تغيير حالة فقط، بلا أي أثر آخر على المخزون أو المحاسبة. */
    public function cancel(int $stockCountId, string $reason, int $userId): StockCount
    {
        $stockCount = StockCount::query()->lockForUpdate()->findOrFail($stockCountId);

        if (! $stockCount->isActive()) {
            throw new DomainException('لا يمكن إلغاء جلسة جرد غير نشطة.');
        }

        $stockCount->update(['status' => 'cancelled']);

        $this->audit->log('stock_count', $stockCount->id, 'stock_count_cancelled', null, ['reason' => $reason]);

        return $stockCount->refresh();
    }
}
