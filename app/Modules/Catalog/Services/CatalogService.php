<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemBarcode;
use App\Modules\Catalog\Models\ItemUnit;
use App\Modules\Catalog\Models\ServiceRecipeLine;
use App\Shared\Audit\AuditService;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * قواعد بطاقة الصنف: الوحدة الأساسية معاملها 1، الأرصدة بالوحدة الأساسية،
 * لا حذف لصنف مستخدم، ولا تغيير للوحدة الأساسية بعد وجود حركات.
 */
final class CatalogService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param array{units: list<array{unit_id:int,to_base_factor:string,sale_price:string,price_includes_tax:bool,barcodes?:list<string>}>} $data */
    public function create(array $data): Item
    {
        return DB::transaction(function () use ($data): Item {
            $this->assertBaseUnitPresent($data);

            $item = Item::query()->create([
                'sku' => $data['sku'],
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'] ?? null,
                'item_type' => $data['item_type'],
                'category_id' => $data['category_id'] ?? null,
                'base_unit_id' => $data['base_unit_id'],
                'tax_code_id' => $data['tax_code_id'] ?? null,
                'reorder_level' => $data['item_type'] === 'stock' ? ($data['reorder_level'] ?? '0') : '0',
                'revenue_account_id' => $data['revenue_account_id'] ?? null,
                'inventory_account_id' => $data['item_type'] === 'stock' ? ($data['inventory_account_id'] ?? null) : null,
                'cost_account_id' => $data['cost_account_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncUnits($item, $data['units']);
            $this->audit->log('item', $item->id, 'created', null, $item->toArray());

            return $item->refresh();
        });
    }

    public function update(Item $item, array $data): Item
    {
        return DB::transaction(function () use ($item, $data): Item {
            $before = $item->toArray();

            if ((int) $data['base_unit_id'] !== (int) $item->base_unit_id && $this->hasMovements($item)) {
                throw new DomainException('لا يمكن تغيير الوحدة الأساسية بعد وجود حركات مخزون على الصنف. أنشئ صنفًا جديدًا أو نفّذ تحويلًا معتمدًا.');
            }

            if ($data['item_type'] !== $item->item_type && $this->hasDocumentLines($item)) {
                throw new DomainException('لا يمكن تغيير نوع الصنف بعد استخدامه في مستندات.');
            }

            $this->assertBaseUnitPresent($data);

            // تعديل السعر أو الضريبة هنا لا يمس أي مستند تاريخي: السطور تحمل لقطاتها
            $item->update([
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'] ?? null,
                'item_type' => $data['item_type'],
                'category_id' => $data['category_id'] ?? null,
                'base_unit_id' => $data['base_unit_id'],
                'tax_code_id' => $data['tax_code_id'] ?? null,
                'reorder_level' => $data['item_type'] === 'stock' ? ($data['reorder_level'] ?? '0') : '0',
                'revenue_account_id' => $data['revenue_account_id'] ?? null,
                'inventory_account_id' => $data['item_type'] === 'stock' ? ($data['inventory_account_id'] ?? null) : null,
                'cost_account_id' => $data['cost_account_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncUnits($item, $data['units']);
            $this->audit->log('item', $item->id, 'updated', $before, $item->fresh()->toArray());

            return $item->refresh();
        });
    }

    /** الإيقاف بدل الحذف: صنف استُخدم في مستند لا يُحذف أبدًا. */
    public function deactivate(Item $item): void
    {
        $item->update(['is_active' => false]);
        $this->audit->log('item', $item->id, 'deactivated');
    }

    public function delete(Item $item): void
    {
        if ($this->hasDocumentLines($item) || $this->hasMovements($item)) {
            throw new DomainException('الصنف مستخدم في مستندات أو حركات؛ أوقفه بدل حذفه.');
        }

        DB::transaction(function () use ($item): void {
            ItemBarcode::query()->whereIn('item_unit_id', $item->units()->pluck('id'))->delete();
            ServiceRecipeLine::query()->whereIn('service_item_unit_id', $item->units()->pluck('id'))->delete();
            $item->units()->delete();
            $item->delete();
        });

        $this->audit->log('item', $item->id, 'deleted');
    }

    /** @param list<array<string,mixed>> $units */
    private function syncUnits(Item $item, array $units): void
    {
        $keepIds = [];

        foreach ($units as $row) {
            $isBase = (int) $row['unit_id'] === (int) $item->base_unit_id;
            $factor = $isBase ? '1' : (string) $row['to_base_factor'];

            if (Money::of($factor)->isZero() || Money::of($factor)->isNegative()) {
                throw new DomainException('معامل التحويل يجب أن يكون أكبر من صفر.');
            }

            $unit = ItemUnit::query()->updateOrCreate(
                ['item_id' => $item->id, 'unit_id' => $row['unit_id']],
                [
                    'to_base_factor' => $factor,
                    'sale_price' => (string) ($row['sale_price'] ?? '0'),
                    'price_includes_tax' => (bool) ($row['price_includes_tax'] ?? true),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ]
            );

            $keepIds[] = $unit->id;
            $this->syncBarcodes($unit, $row['barcodes'] ?? []);
        }

        $removable = $item->units()->whereNotIn('id', $keepIds)->get();

        foreach ($removable as $unit) {
            $used = DB::table('document_lines')->where('item_unit_id', $unit->id)->exists()
                || ServiceRecipeLine::query()->where('service_item_unit_id', $unit->id)->exists();

            if ($used) {
                $unit->update(['is_active' => false]);

                continue;
            }

            ItemBarcode::query()->where('item_unit_id', $unit->id)->delete();
            $unit->delete();
        }
    }

    /** @param list<string> $barcodes */
    private function syncBarcodes(ItemUnit $unit, array $barcodes): void
    {
        $barcodes = array_values(array_filter(array_map('trim', $barcodes)));

        ItemBarcode::query()->where('item_unit_id', $unit->id)->whereNotIn('barcode', $barcodes ?: ['-'])->delete();

        foreach ($barcodes as $barcode) {
            $owner = ItemBarcode::query()->where('barcode', $barcode)->first();

            if ($owner && (int) $owner->item_unit_id !== (int) $unit->id) {
                throw new DomainException("الباركود {$barcode} مستخدم على وحدة أخرى.");
            }

            ItemBarcode::query()->firstOrCreate(['barcode' => $barcode], ['item_unit_id' => $unit->id]);
        }
    }

    private function assertBaseUnitPresent(array $data): void
    {
        $baseUnitRows = array_filter($data['units'], fn ($u) => (int) $u['unit_id'] === (int) $data['base_unit_id']);

        if (count($baseUnitRows) !== 1) {
            throw new DomainException('يجب أن تكون الوحدة الأساسية ضمن وحدات الصنف مرة واحدة، ومعاملها 1.');
        }
    }

    /** @param list<array{material_item_id:int, base_quantity:string, is_active?:bool}> $lines */
    public function saveRecipe(ItemUnit $serviceUnit, array $lines): void
    {
        if (! $serviceUnit->item->isService()) {
            throw new DomainException('وصفة الخامات تُعرّف على وحدة خدمة فقط.');
        }

        DB::transaction(function () use ($serviceUnit, $lines): void {
            $keep = [];

            foreach ($lines as $line) {
                $material = Item::query()->findOrFail($line['material_item_id']);

                if (! $material->isStock()) {
                    throw new DomainException("الخامة «{$material->name_ar}» ليست صنف مخزون؛ الوصفة تستهلك أصناف مخزون فقط.");
                }

                $row = ServiceRecipeLine::query()->updateOrCreate(
                    ['service_item_unit_id' => $serviceUnit->id, 'material_item_id' => $material->id],
                    ['base_quantity' => (string) $line['base_quantity'], 'is_active' => (bool) ($line['is_active'] ?? true)]
                );

                $keep[] = $row->id;
            }

            // الوصفة الحالية لا تمس الاستهلاك التاريخي: الحركات القديمة ثابتة في stock_movements
            ServiceRecipeLine::query()
                ->where('service_item_unit_id', $serviceUnit->id)
                ->whereNotIn('id', $keep ?: [0])
                ->delete();
        });

        $this->audit->log('service_recipe', $serviceUnit->id, 'updated', null, ['lines' => $lines]);
    }

    private function hasMovements(Item $item): bool
    {
        return DB::table('stock_movements')->where('item_id', $item->id)->exists();
    }

    private function hasDocumentLines(Item $item): bool
    {
        return DB::table('document_lines')->where('item_id', $item->id)->exists();
    }
}
