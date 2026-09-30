<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockCount;
use DomainException;

/** في النسخة الأولى: جلسة جرد نشطة تجمّد حركة المخزن كلها. */
final class StockFreezeGuard
{
    public function assertNotFrozen(?int $warehouseId): void
    {
        if ($warehouseId === null) {
            return;
        }

        $active = StockCount::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', ['draft', 'counting'])
            ->first();

        if ($active) {
            throw new DomainException('يوجد جرد نشط على المخزن؛ حركة المخزون مجمّدة حتى اعتماد الجرد أو إلغائه.');
        }
    }
}
