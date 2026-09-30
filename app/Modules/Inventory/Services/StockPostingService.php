<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockMovement;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * دفتر المخزون: الحركات هي المصدر، والأرصدة تجميع محفوظ يُحدَّث في المعاملة نفسها.
 * التكلفة بالمتوسط المرجح المتحرك:
 *   متوسط جديد = (قيمة الرصيد السابق + تكلفة الوارد) ÷ (كمية سابقة + كمية واردة)
 *
 * كل الدوال تُستدعى داخل معاملة قائمة، وتقفل صفوف الأرصدة بترتيب ثابت
 * (warehouse_id ثم item_id) لمنع التعارض بين شاشتين.
 */
final class StockPostingService
{
    /** قفل أرصدة عدة أصناف بترتيب ثابت قبل أي تعديل. */
    public function lockBalances(int $warehouseId, array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        sort($itemIds);

        foreach ($itemIds as $itemId) {
            $this->balanceRow($warehouseId, $itemId, lock: true);
        }
    }

    public function availableQuantity(int $warehouseId, int $itemId): Money
    {
        $balance = Money::of(DB::table('stock_balances')
            ->where('warehouse_id', $warehouseId)->where('item_id', $itemId)->value('quantity') ?? '0');

        $reserved = Money::of(DB::table('stock_reservations')
            ->where('warehouse_id', $warehouseId)->where('item_id', $itemId)
            ->where('status', 'active')->sum('quantity') ?? '0');

        return $balance->minus($reserved);
    }

    public function averageCost(int $warehouseId, int $itemId): Money
    {
        return Money::of(DB::table('stock_balances')
            ->where('warehouse_id', $warehouseId)->where('item_id', $itemId)->value('average_cost') ?? '0');
    }

    /**
     * وارد: شراء، رصيد افتتاحي، مرتجع بيع، تسوية زيادة.
     */
    public function receive(
        int $warehouseId,
        int $itemId,
        Money $baseQuantity,
        Money $totalCost,
        string $movementType,
        int $documentId,
        int $documentLineId,
        int $componentNumber = 0,
        ?Carbon $occurredAt = null,
        ?int $reversesMovementId = null,
    ): StockMovement {
        $this->assertPositive($baseQuantity);

        if ($totalCost->isNegative()) {
            throw new DomainException('لا يمكن أن تكون تكلفة الوارد سالبة.');
        }

        $occurredAt ??= now();
        $balance = $this->balanceRow($warehouseId, $itemId, lock: true);
        $this->assertNotBackdated($warehouseId, $itemId, $occurredAt);

        $newQuantity = Money::of($balance->quantity)->plus($baseQuantity);
        $newValue = Money::of($balance->inventory_value)->plus($totalCost);
        $newAverage = $newQuantity->isZero() ? Money::zero() : $newValue->dividedBy($newQuantity);

        $this->writeBalance($balance->id, $newQuantity, $newValue, $newAverage);

        return $this->writeMovement([
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'document_id' => $documentId,
            'document_line_id' => $documentLineId,
            'component_number' => $componentNumber,
            'reverses_movement_id' => $reversesMovementId,
            'movement_type' => $movementType,
            'quantity_delta' => $baseQuantity->toDb(),
            'value_delta' => $totalCost->toDb(),
            'unit_cost' => $baseQuantity->isZero() ? '0' : $totalCost->dividedBy($baseQuantity)->toDb(),
            'balance_after' => $newQuantity->toDb(),
            'value_after' => $newValue->toDb(),
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * صادر بالمتوسط الحالي: بيع، استهلاك خامات، تلف، مرتجع شراء، تسوية نقص.
     * يمنع المخزون السالب، ويصفّر القيمة عند تصفير الكمية (معالجة فرق التقريب).
     */
    public function issue(
        int $warehouseId,
        int $itemId,
        Money $baseQuantity,
        string $movementType,
        int $documentId,
        int $documentLineId,
        int $componentNumber = 0,
        ?Carbon $occurredAt = null,
        ?Money $forcedUnitCost = null,
        ?int $reversesMovementId = null,
        bool $ignoreReservations = false,
    ): StockMovement {
        $this->assertPositive($baseQuantity);

        $occurredAt ??= now();
        $balance = $this->balanceRow($warehouseId, $itemId, lock: true);
        $this->assertNotBackdated($warehouseId, $itemId, $occurredAt);

        $currentQuantity = Money::of($balance->quantity);
        $currentValue = Money::of($balance->inventory_value);

        if ($baseQuantity->isGreaterThan($currentQuantity)) {
            throw new DomainException('الكمية المطلوبة أكبر من رصيد المخزون؛ لا يُسمح برصيد سالب.');
        }

        if (! $ignoreReservations) {
            $available = $this->availableQuantity($warehouseId, $itemId);

            if ($baseQuantity->isGreaterThan($available)) {
                throw new DomainException('الكمية المتاحة بعد الحجوزات لا تكفي لهذا الصرف.');
            }
        }

        $unitCost = $forcedUnitCost ?? Money::of($balance->average_cost);
        $newQuantity = $currentQuantity->minus($baseQuantity);

        // عند تصفير الكمية تُصفَّر القيمة بالكامل حتى لا يبقى كسر تقريب في المخزون
        $valueDelta = $newQuantity->isZero() ? $currentValue : $unitCost->multipliedBy($baseQuantity);

        if ($valueDelta->isGreaterThan($currentValue)) {
            $valueDelta = $currentValue;
        }

        $newValue = $currentValue->minus($valueDelta);
        $newAverage = $newQuantity->isZero() ? Money::zero() : $newValue->dividedBy($newQuantity);

        $this->writeBalance($balance->id, $newQuantity, $newValue, $newAverage);

        return $this->writeMovement([
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'document_id' => $documentId,
            'document_line_id' => $documentLineId,
            'component_number' => $componentNumber,
            'reverses_movement_id' => $reversesMovementId,
            'movement_type' => $movementType,
            'quantity_delta' => $baseQuantity->negated()->toDb(),
            'value_delta' => $valueDelta->negated()->toDb(),
            'unit_cost' => $baseQuantity->isZero() ? '0' : $valueDelta->dividedBy($baseQuantity)->toDb(),
            'balance_after' => $newQuantity->toDb(),
            'value_after' => $newValue->toDb(),
            'occurred_at' => $occurredAt,
        ]);
    }

    /** حجز كمية لمستند ينتظر التخليص. */
    public function reserve(int $warehouseId, int $itemId, Money $baseQuantity, int $documentId, int $documentLineId, int $componentNumber = 0): void
    {
        $this->assertPositive($baseQuantity);
        $this->balanceRow($warehouseId, $itemId, lock: true);

        if ($baseQuantity->isGreaterThan($this->availableQuantity($warehouseId, $itemId))) {
            throw new DomainException('لا توجد كمية متاحة كافية للحجز.');
        }

        DB::table('stock_reservations')->insert([
            'document_id' => $documentId,
            'document_line_id' => $documentLineId,
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'component_number' => $componentNumber,
            'quantity' => $baseQuantity->toDb(),
            'status' => 'active',
            'created_at' => now(),
        ]);
    }

    public function consumeReservations(int $documentId): void
    {
        DB::table('stock_reservations')
            ->where('document_id', $documentId)
            ->where('status', 'active')
            ->update(['status' => 'consumed', 'resolved_at' => now()]);
    }

    /** لا تُستدعى تلقائيًا عند نتيجة اتصال مجهولة: الإفراج قرار موثق. */
    public function releaseReservations(int $documentId): void
    {
        DB::table('stock_reservations')
            ->where('document_id', $documentId)
            ->where('status', 'active')
            ->update(['status' => 'released', 'resolved_at' => now()]);
    }

    public function activeReservationQuantity(int $documentId): Money
    {
        return Money::of(DB::table('stock_reservations')
            ->where('document_id', $documentId)->where('status', 'active')->sum('quantity') ?? '0');
    }

    private function balanceRow(int $warehouseId, int $itemId, bool $lock = false): object
    {
        $query = DB::table('stock_balances')->where('warehouse_id', $warehouseId)->where('item_id', $itemId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row) {
            return $row;
        }

        DB::table('stock_balances')->insertOrIgnore([
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'quantity' => 0,
            'inventory_value' => 0,
            'average_cost' => 0,
            'version' => 0,
        ]);

        return DB::table('stock_balances')
            ->where('warehouse_id', $warehouseId)->where('item_id', $itemId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    private function writeBalance(int $balanceId, Money $quantity, Money $value, Money $average): void
    {
        DB::table('stock_balances')->where('id', $balanceId)->update([
            'quantity' => $quantity->toDb(),
            'inventory_value' => $value->toDb(),
            'average_cost' => $average->toDb(),
            'version' => DB::raw('version + 1'),
        ]);
    }

    private function writeMovement(array $attributes): StockMovement
    {
        $id = DB::table('stock_movements')->insertGetId($attributes + ['created_at' => now()]);

        return StockMovement::query()->findOrFail($id);
    }

    /** النسخة الأولى لا تقبل حركة مؤرخة قبل أحدث حركة مرحلة للصنف. */
    private function assertNotBackdated(int $warehouseId, int $itemId, Carbon $occurredAt): void
    {
        $last = DB::table('stock_movements')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('occurred_at');

        if ($last !== null && $occurredAt->lt(Carbon::parse($last))) {
            throw new DomainException('لا يمكن تسجيل حركة مخزون بتاريخ أقدم من آخر حركة مرحلة لهذا الصنف.');
        }
    }

    private function assertPositive(Money $quantity): void
    {
        if (! $quantity->isPositive()) {
            throw new DomainException('كمية الحركة يجب أن تكون أكبر من صفر.');
        }
    }
}
