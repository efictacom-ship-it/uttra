<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Inventory\Services\StockDocumentService;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Purchasing\Services\PurchasePostingService;
use App\Modules\Sales\Services\SalesPostingService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Services\DocumentDraftService;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsUpBusiness;
use Tests\TestCase;

class PostingEngineTest extends TestCase
{
    use RefreshDatabase, SetsUpBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_purchase_by_box_then_sale_by_piece_uses_base_unit_everywhere(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد القرطاسية');

        // شراء 10 علب × 12 قطعة بسعر 12 ريال للعلبة (غير شامل الضريبة)
        $purchase = $this->draft(Document::KIND_PURCHASE, [
            'party_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-02-01',
            'lines' => [[
                'item_id' => $item->id,
                'item_unit_id' => $this->itemUnitId($item, 'BOX'),
                'quantity' => '10',
                'unit_price' => '12',
                'price_includes_tax' => false,
                'description' => 'قلم جاف - علبة',
            ]],
        ]);

        app(PurchasePostingService::class)->post($purchase->id, $this->owner->id);

        $balance = DB::table('stock_balances')->where('item_id', $item->id)->first();
        $this->assertSame('120.000000', $balance->quantity, 'الرصيد يُحفظ بالوحدة الأساسية');
        $this->assertSame('120.000000', $balance->inventory_value);
        $this->assertSame('1.000000', $balance->average_cost);

        // بيع قطعتين
        $sale = $this->draft(Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-02-02',
            'lines' => [[
                'item_id' => $item->id,
                'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '2',
                'unit_price' => '2.30',
                'price_includes_tax' => true,
                'description' => 'قلم جاف - قطعة',
            ]],
        ]);

        $posted = app(SalesPostingService::class)->post($sale->id, [[
            'method' => 'cash',
            'financial_account_id' => $this->financialAccount('cash')->id,
            'amount' => '4.60',
        ]], $this->owner->id);

        $balance = DB::table('stock_balances')->where('item_id', $item->id)->first();
        $this->assertSame('118.000000', $balance->quantity);
        $this->assertSame('118.000000', $balance->inventory_value);

        // 2.30 شامل 15% ⇒ صافي 2.00 للقطعة
        $this->assertSame('4.000000', $posted->net_total);
        $this->assertSame('0.600000', $posted->tax_total);
        $this->assertSame('4.600000', $posted->grand_total);

        $this->assertLedgerBalanced();
        $this->assertInventoryLedgerMatchesStock();
    }

    public function test_moving_average_cost_across_purchases_at_different_prices(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');

        $this->postPurchase($item, $supplier->id, quantity: '100', unitPrice: '1.00', unit: 'PCE', date: '2026-02-01');
        $this->postPurchase($item, $supplier->id, quantity: '100', unitPrice: '2.00', unit: 'PCE', date: '2026-02-02');

        $balance = DB::table('stock_balances')->where('item_id', $item->id)->first();

        $this->assertSame('200.000000', $balance->quantity);
        $this->assertSame('300.000000', $balance->inventory_value);
        $this->assertSame('1.500000', $balance->average_cost, 'المتوسط المرجح المتحرك = 300 ÷ 200');
    }

    public function test_invoice_mixing_goods_and_service_consumes_recipe_materials(): void
    {
        $paper = $this->makeStockItem('PAPER-A4', '0.10', '5.00');
        $pen = $this->makeStockItem('PEN-1', '2.30', '25.00');
        $service = $this->makeServiceItem('COPY-BW', '0.25');
        $supplier = $this->makeParty(false, true, 'مورد الورق');

        $this->postPurchase($paper, $supplier->id, '1000', '0.04', 'PCE', '2026-02-01');
        $this->postPurchase($pen, $supplier->id, '100', '1.00', 'PCE', '2026-02-01');

        // وجه مطبوع واحد = نصف ورقة
        app(CatalogService::class)->saveRecipe(
            $service->units()->firstOrFail(),
            [['material_item_id' => $paper->id, 'base_quantity' => '0.5']]
        );

        $sale = $this->draft(Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-02-03',
            'lines' => [
                [
                    'item_id' => $pen->id, 'item_unit_id' => $this->itemUnitId($pen, 'PCE'),
                    'quantity' => '1', 'unit_price' => '2.30', 'price_includes_tax' => true, 'description' => 'قلم',
                ],
                [
                    'item_id' => $service->id, 'item_unit_id' => $this->itemUnitId($service, 'SIDE'),
                    'quantity' => '40', 'unit_price' => '0.25', 'price_includes_tax' => true, 'description' => 'تصوير 40 وجهًا',
                ],
            ],
        ]);

        $posted = app(SalesPostingService::class)->post($sale->id, [[
            'method' => 'cash', 'financial_account_id' => $this->financialAccount('cash')->id, 'amount' => $posted_total = '12.30',
        ]], $this->owner->id);

        $this->assertSame('12.300000', $posted->grand_total);

        // 40 وجهًا × 0.5 ورقة = 20 ورقة مستهلكة
        $paperBalance = DB::table('stock_balances')->where('item_id', $paper->id)->first();
        $this->assertSame('980.000000', $paperBalance->quantity);

        $materialMovements = DB::table('stock_movements')
            ->where('document_id', $posted->id)->where('movement_type', 'material_usage')->get();

        $this->assertCount(1, $materialMovements);
        $this->assertSame('-20.000000', $materialMovements[0]->quantity_delta);
        $this->assertSame(1, (int) $materialMovements[0]->component_number, 'استهلاك الخامة يُرقَّم كمكوّن للسطر');

        $this->assertLedgerBalanced();
        $this->assertInventoryLedgerMatchesStock();
    }

    public function test_service_without_recipe_has_no_stock_movement(): void
    {
        $service = $this->makeServiceItem('BIND-1', '5.00');

        $sale = $this->draft(Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-02-03',
            'lines' => [[
                'item_id' => $service->id, 'item_unit_id' => $this->itemUnitId($service, 'SIDE'),
                'quantity' => '2', 'unit_price' => '5.00', 'price_includes_tax' => true, 'description' => 'تجليد',
            ]],
        ]);

        $posted = app(SalesPostingService::class)->post($sale->id, [[
            'method' => 'card', 'financial_account_id' => $this->financialAccount('card_clearing')->id, 'amount' => '10.00',
        ]], $this->owner->id);

        $this->assertSame(0, DB::table('stock_movements')->where('document_id', $posted->id)->count());
        $this->assertLedgerBalanced();
    }

    public function test_mixed_payment_and_credit_sale_require_a_customer(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');
        $this->postPurchase($item, $supplier->id, '100', '1.00', 'PCE', '2026-02-01');

        $sale = $this->draft(Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-02-04',
            'lines' => [[
                'item_id' => $item->id, 'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '10', 'unit_price' => '2.30', 'price_includes_tax' => true, 'description' => 'أقلام',
            ]],
        ]);

        // دفع جزئي بلا عميل مرفوض
        try {
            app(SalesPostingService::class)->post($sale->id, [[
                'method' => 'cash', 'financial_account_id' => $this->financialAccount('cash')->id, 'amount' => '10.00',
            ]], $this->owner->id);
            $this->fail('كان يجب رفض البيع الآجل بلا عميل');
        } catch (DomainException $e) {
            $this->assertStringContainsString('عميل', $e->getMessage());
        }

        $customer = $this->makeParty(true, false, 'عميل مدرسة');
        $sale->refresh()->update(['party_id' => $customer->id]);

        $posted = app(SalesPostingService::class)->post($sale->id, [
            ['method' => 'cash', 'financial_account_id' => $this->financialAccount('cash')->id, 'amount' => '10.00'],
            ['method' => 'card', 'financial_account_id' => $this->financialAccount('card_clearing')->id, 'amount' => '5.00'],
        ], $this->owner->id);

        $this->assertSame('23.000000', $posted->grand_total);
        $this->assertSame(2, DB::table('payments')->where('status', 'posted')->count());

        $outstanding = DB::table('payment_allocations')->where('document_id', $posted->id)->sum('amount');
        $this->assertSame('15.000000', number_format((float) $outstanding, 6, '.', ''));

        $this->assertLedgerBalanced();
    }

    public function test_sale_cannot_exceed_available_stock(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');
        $this->postPurchase($item, $supplier->id, '3', '1.00', 'PCE', '2026-02-01');

        $sale = $this->draft(Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-02-05',
            'lines' => [[
                'item_id' => $item->id, 'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '5', 'unit_price' => '2.30', 'price_includes_tax' => true, 'description' => 'أقلام',
            ]],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('أكبر من رصيد المخزون');

        app(SalesPostingService::class)->post($sale->id, [[
            'method' => 'cash', 'financial_account_id' => $this->financialAccount('cash')->id, 'amount' => '11.50',
        ]], $this->owner->id);
    }

    public function test_failed_posting_rolls_everything_back(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');
        $this->postPurchase($item, $supplier->id, '3', '1.00', 'PCE', '2026-02-01');

        $movementsBefore = DB::table('stock_movements')->count();
        $entriesBefore = DB::table('journal_entries')->count();

        $sale = $this->draft(Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-02-05',
            'lines' => [
                ['item_id' => $item->id, 'item_unit_id' => $this->itemUnitId($item, 'PCE'), 'quantity' => '1', 'unit_price' => '2.30', 'price_includes_tax' => true, 'description' => 'سطر سليم'],
                ['item_id' => $item->id, 'item_unit_id' => $this->itemUnitId($item, 'BOX'), 'quantity' => '9', 'unit_price' => '25', 'price_includes_tax' => true, 'description' => 'سطر يفشل'],
            ],
        ]);

        try {
            app(SalesPostingService::class)->post($sale->id, [], $this->owner->id);
        } catch (DomainException) {
            // متوقع
        }

        $this->assertSame($movementsBefore, DB::table('stock_movements')->count(), 'لا حركة مخزون بعد فشل منتصف الترحيل');
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count(), 'لا قيد بعد فشل منتصف الترحيل');
        $this->assertSame('draft', $sale->fresh()->status);
    }

    public function test_posting_is_blocked_in_a_closed_period(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');
        $this->postPurchase($item, $supplier->id, '10', '1.00', 'PCE', '2026-02-01');

        DB::table('accounting_periods')->update(['status' => 'closed']);

        $sale = $this->draft(Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-02-06',
            'lines' => [[
                'item_id' => $item->id, 'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '1', 'unit_price' => '2.30', 'price_includes_tax' => true, 'description' => 'قلم',
            ]],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('مغلقة');

        app(SalesPostingService::class)->post($sale->id, [], $this->owner->id);
    }

    public function test_stock_opening_creates_inventory_against_opening_account(): void
    {
        $item = $this->makeStockItem();

        $opening = $this->draft(Document::KIND_STOCK_OPENING, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-01-01',
            'lines' => [[
                'item_id' => $item->id, 'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '50', 'unit_price' => '0', 'unit_cost' => '1.20',
                'stock_direction' => 'in', 'description' => 'رصيد افتتاحي',
            ]],
        ]);

        app(StockDocumentService::class)->post($opening->id, $this->owner->id);

        $balance = DB::table('stock_balances')->where('item_id', $item->id)->first();
        $this->assertSame('50.000000', $balance->quantity);
        $this->assertSame('60.000000', $balance->inventory_value);

        $this->assertLedgerBalanced();
        $this->assertInventoryLedgerMatchesStock();
    }

    public function test_inventory_value_reaches_zero_exactly_when_quantity_is_zero(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');

        // تكلفة لا تقبل القسمة بدقة: 3 قطع بريال ثم 4 قطع بـ 0.33 ⇒ متوسط دوري
        $this->postPurchase($item, $supplier->id, '3', '1.00', 'PCE', '2026-02-01');
        $this->postPurchase($item, $supplier->id, '4', '0.33', 'PCE', '2026-02-01');

        $stock = app(StockPostingService::class);
        $available = $stock->availableQuantity($this->warehouse->id, $item->id);

        $sale = $this->draft(Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-02-07',
            'lines' => [[
                'item_id' => $item->id, 'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => $available->toDb(), 'unit_price' => '2.30', 'price_includes_tax' => true, 'description' => 'تصفية',
            ]],
        ]);

        app(SalesPostingService::class)->post($sale->id, [[
            'method' => 'cash', 'financial_account_id' => $this->financialAccount('cash')->id,
            'amount' => Money::of('2.30')->multipliedBy($available)->roundTo(2)->toDecimalString(2),
        ]], $this->owner->id);

        $balance = DB::table('stock_balances')->where('item_id', $item->id)->first();
        $this->assertSame('0.000000', $balance->quantity);
        $this->assertSame('0.000000', $balance->inventory_value, 'تصفير الكمية يصفّر القيمة بلا كسور تقريب');
        $this->assertInventoryLedgerMatchesStock();
    }
}
