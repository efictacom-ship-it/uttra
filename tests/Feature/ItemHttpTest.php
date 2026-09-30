<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsUpBusiness;
use Tests\TestCase;

class ItemHttpTest extends TestCase
{
    use RefreshDatabase, SetsUpBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_items_can_be_created_through_the_screen(): void
    {
        $unit = $this->unit('PCE');

        $payload = [
            'sku' => 'TEST-1',
            'name_ar' => 'قلم تجربة',
            'name_en' => '',
            'item_type' => 'stock',
            'category_id' => '',
            'base_unit_id' => $unit->id,
            'tax_code_id' => '',
            'reorder_level' => '0',
            'revenue_account_id' => '',
            'inventory_account_id' => '',
            'cost_account_id' => '',
            'is_active' => true,
            'units' => [
                ['unit_id' => $unit->id, 'to_base_factor' => '1', 'sale_price' => '5', 'price_includes_tax' => true, 'is_active' => true, 'barcodes' => []],
            ],
        ];

        $response = $this->actingAs($this->owner)->post('/items', $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(1, Item::query()->where('sku', 'TEST-1')->count());
    }
}
