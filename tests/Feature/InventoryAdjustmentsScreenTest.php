<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsUpBusiness;
use Tests\TestCase;

class InventoryAdjustmentsScreenTest extends TestCase
{
    use RefreshDatabase, SetsUpBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_adjustments_screen_renders(): void
    {
        $this->actingAs($this->owner)
            ->get('/inventory/adjustments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Adjustments'));
    }

    /** نفس الحمولة التي ترسلها الشاشة لإدخال رصيد افتتاحي. */
    public function test_opening_balance_can_be_submitted_from_the_screen(): void
    {
        $item = $this->makeStockItem();

        $this->actingAs($this->owner)
            ->post('/inventory/opening', [
                'warehouse_id' => $this->warehouse->id,
                'business_date' => '2026-02-01',
                'notes' => 'افتتاحي تجريبي',
                'lines' => [[
                    'item_id' => $item->id,
                    'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                    'quantity' => '40',
                    'unit_cost' => '1.50',
                    'description' => 'رصيد افتتاحي - '.$item->name_ar,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('40.000000', (string) \Illuminate\Support\Facades\DB::table('stock_balances')
            ->where('item_id', $item->id)->value('quantity'));
    }

    /** نفس الحمولة التي ترسلها الشاشة للتلف، مع تكلفة وحدة فارغة (تُرسل null). */
    public function test_adjustment_can_be_submitted_from_the_screen(): void
    {
        $item = $this->makeStockItem('PEN-2');

        $this->actingAs($this->owner)->post('/inventory/opening', [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-02-01',
            'lines' => [[
                'item_id' => $item->id,
                'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '10',
                'unit_cost' => '2.00',
                'description' => 'افتتاحي',
            ]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->post('/inventory/adjustments', [
                'warehouse_id' => $this->warehouse->id,
                'business_date' => '2026-02-02',
                'reason' => 'تلف بسبب المياه',
                'lines' => [[
                    'item_id' => $item->id,
                    'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                    'quantity' => '3',
                    'stock_direction' => 'out',
                    'unit_cost' => null,
                    'description' => $item->name_ar,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('7.000000', (string) \Illuminate\Support\Facades\DB::table('stock_balances')
            ->where('item_id', $item->id)->value('quantity'));
    }
}
