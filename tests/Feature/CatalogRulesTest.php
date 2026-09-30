<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\ItemBarcode;
use App\Modules\Catalog\Models\ItemUnit;
use App\Modules\Catalog\Services\CatalogService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsUpBusiness;
use Tests\TestCase;

class CatalogRulesTest extends TestCase
{
    use RefreshDatabase, SetsUpBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_item_has_base_unit_with_factor_one_and_a_box_of_twelve(): void
    {
        $item = $this->makeStockItem();

        $base = $item->units()->where('unit_id', $this->unit('PCE')->id)->firstOrFail();
        $box = $item->units()->where('unit_id', $this->unit('BOX')->id)->firstOrFail();

        $this->assertSame('1.000000', $base->to_base_factor);
        $this->assertSame('12.000000', $box->to_base_factor);
        $this->assertSame(2, ItemBarcode::query()->count());
    }

    public function test_base_unit_factor_is_forced_to_one_even_if_sent_otherwise(): void
    {
        $item = app(CatalogService::class)->create([
            'sku' => 'NOTE-1', 'name_ar' => 'دفتر', 'item_type' => 'stock',
            'base_unit_id' => $this->unit('PCE')->id, 'is_active' => true,
            'units' => [
                ['unit_id' => $this->unit('PCE')->id, 'to_base_factor' => '5', 'sale_price' => '3', 'price_includes_tax' => true],
            ],
        ]);

        $this->assertSame('1.000000', $item->units()->first()->to_base_factor);
    }

    public function test_item_without_its_base_unit_is_rejected(): void
    {
        $this->expectException(DomainException::class);

        app(CatalogService::class)->create([
            'sku' => 'BAD-1', 'name_ar' => 'صنف ناقص', 'item_type' => 'stock',
            'base_unit_id' => $this->unit('PCE')->id, 'is_active' => true,
            'units' => [
                ['unit_id' => $this->unit('BOX')->id, 'to_base_factor' => '12', 'sale_price' => '10', 'price_includes_tax' => true],
            ],
        ]);
    }

    public function test_barcode_cannot_be_reused_on_another_item(): void
    {
        $this->makeStockItem('PEN-1');

        $reusedBarcode = $this->barcodeFor('PEN-1', 1);

        $this->expectException(DomainException::class);

        app(CatalogService::class)->create([
            'sku' => 'PEN-2', 'name_ar' => 'قلم آخر', 'item_type' => 'stock',
            'base_unit_id' => $this->unit('PCE')->id, 'is_active' => true,
            'units' => [
                ['unit_id' => $this->unit('PCE')->id, 'to_base_factor' => '1', 'sale_price' => '2', 'price_includes_tax' => true, 'barcodes' => [$reusedBarcode]],
            ],
        ]);
    }

    public function test_service_recipe_accepts_stock_materials_only(): void
    {
        $service = $this->makeServiceItem();
        $otherService = $this->makeServiceItem('BIND-1', '5.00');
        $serviceUnit = $service->units()->firstOrFail();

        $this->expectException(DomainException::class);

        app(CatalogService::class)->saveRecipe($serviceUnit, [
            ['material_item_id' => $otherService->id, 'base_quantity' => '1'],
        ]);
    }

    public function test_recipe_is_saved_for_a_service_unit(): void
    {
        $paper = $this->makeStockItem('PAPER-A4', '0.05', '10');
        $service = $this->makeServiceItem();
        $serviceUnit = $service->units()->firstOrFail();

        // وجه مطبوع واحد = نصف ورقة عند الطباعة على الوجهين
        app(CatalogService::class)->saveRecipe($serviceUnit, [
            ['material_item_id' => $paper->id, 'base_quantity' => '0.5'],
        ]);

        $this->assertDatabaseHas('service_recipe_lines', [
            'service_item_unit_id' => $serviceUnit->id,
            'material_item_id' => $paper->id,
            'base_quantity' => '0.500000',
        ]);
    }

    public function test_base_unit_cannot_change_after_stock_movements(): void
    {
        $item = $this->makeStockItem();
        $this->fakeMovement($item->id);

        $this->expectException(DomainException::class);

        app(CatalogService::class)->update($item, [
            'sku' => $item->sku, 'name_ar' => $item->name_ar, 'item_type' => 'stock',
            'base_unit_id' => $this->unit('BOX')->id, 'is_active' => true,
            'units' => [
                ['unit_id' => $this->unit('BOX')->id, 'to_base_factor' => '1', 'sale_price' => '22', 'price_includes_tax' => true],
            ],
        ]);
    }

    public function test_used_item_is_deactivated_not_deleted(): void
    {
        $item = $this->makeStockItem();
        $this->fakeMovement($item->id);

        try {
            app(CatalogService::class)->delete($item);
            $this->fail('كان يجب رفض حذف صنف عليه حركات');
        } catch (DomainException) {
            // متوقع
        }

        app(CatalogService::class)->deactivate($item);

        $this->assertFalse((bool) $item->fresh()->is_active);
        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    public function test_unit_used_in_a_document_is_deactivated_instead_of_removed(): void
    {
        $item = $this->makeStockItem();
        $boxUnit = $item->units()->where('unit_id', $this->unit('BOX')->id)->firstOrFail();
        $this->fakeDocumentLine($item->id, $boxUnit->id);

        app(CatalogService::class)->update($item, [
            'sku' => $item->sku, 'name_ar' => $item->name_ar, 'item_type' => 'stock',
            'base_unit_id' => $this->unit('PCE')->id, 'is_active' => true,
            'units' => [
                ['unit_id' => $this->unit('PCE')->id, 'to_base_factor' => '1', 'sale_price' => '2', 'price_includes_tax' => true],
            ],
        ]);

        $this->assertFalse((bool) ItemUnit::query()->find($boxUnit->id)->is_active);
    }

    private function fakeMovement(int $itemId): void
    {
        $documentId = $this->fakeDocumentLine($itemId, null, true);

        DB::table('stock_movements')->insert([
            'warehouse_id' => $this->warehouse->id,
            'item_id' => $itemId,
            'document_id' => $documentId['document_id'],
            'document_line_id' => $documentId['line_id'],
            'component_number' => 0,
            'movement_type' => 'opening',
            'quantity_delta' => 10, 'value_delta' => 10, 'unit_cost' => 1,
            'balance_after' => 10, 'value_after' => 10, 'occurred_at' => now(),
        ]);
    }

    /** @return array{document_id:int, line_id:int}|int */
    private function fakeDocumentLine(int $itemId, ?int $itemUnitId = null, bool $returnIds = false)
    {
        $itemUnitId ??= ItemUnit::query()->where('item_id', $itemId)->value('id');

        $documentId = DB::table('documents')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'kind' => 'stock_opening',
            'business_date' => '2026-01-05', 'idempotency_key' => 'test-'.uniqid(),
            'created_by' => $this->owner->id, 'warehouse_id' => $this->warehouse->id, 'status' => 'posted',
        ]);

        $lineId = DB::table('document_lines')->insertGetId([
            'document_id' => $documentId, 'line_number' => 1, 'item_id' => $itemId, 'item_unit_id' => $itemUnitId,
            'description' => 'سطر اختبار', 'item_type_snapshot' => 'stock', 'unit_name_snapshot' => 'قطعة',
            'quantity' => 10, 'to_base_factor' => 1, 'base_quantity' => 10, 'stock_direction' => 'in',
        ]);

        return $returnIds ? ['document_id' => $documentId, 'line_id' => $lineId] : $documentId;
    }
}
