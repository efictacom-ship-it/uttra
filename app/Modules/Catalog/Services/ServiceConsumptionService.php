<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ServiceRecipeLine;
use App\Shared\Money\Money;

/**
 * تحويل وصفة الخدمة إلى استهلاك خامات فعلي وقت البيع.
 * الاستهلاك يُثبَّت في حركات المخزون، فلا يتغير التاريخ عند تعديل الوصفة لاحقًا،
 * ولا تُعاد الخامات تلقائيًا عند رد قيمة الخدمة.
 */
final class ServiceConsumptionService
{
    /**
     * @return list<array{material_item_id:int, base_quantity:Money}>
     */
    public function consumptionFor(int $serviceItemUnitId, Money $serviceQuantity): array
    {
        $recipe = ServiceRecipeLine::query()
            ->where('service_item_unit_id', $serviceItemUnitId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $result = [];

        foreach ($recipe as $line) {
            $result[] = [
                'material_item_id' => (int) $line->material_item_id,
                'base_quantity' => Money::of($line->base_quantity)->multipliedBy($serviceQuantity),
            ];
        }

        return $result;
    }

    public function hasRecipe(int $serviceItemUnitId): bool
    {
        return ServiceRecipeLine::query()
            ->where('service_item_unit_id', $serviceItemUnitId)
            ->where('is_active', true)
            ->exists();
    }
}
