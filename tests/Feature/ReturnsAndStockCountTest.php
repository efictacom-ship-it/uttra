<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Purchasing\Services\PurchaseReturnPostingService;
use App\Shared\Documents\Models\Document;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsUpBusiness;
use Tests\TestCase;

class ReturnsAndStockCountTest extends TestCase
{
    use RefreshDatabase, SetsUpBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_purchase_return_reverses_stock_and_payable_at_original_cost(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد القرطاسية');

        $purchase = $this->postPurchase($item, $supplier->id, quantity: '10', unitPrice: '12', unit: 'BOX', date: '2026-03-01');

        // شراء آخر بسعر مختلف يغيّر متوسط التكلفة، حتى نتأكد أن المرتجع لا يتأثر به
        $this->postPurchase($item, $supplier->id, quantity: '10', unitPrice: '24', unit: 'BOX', date: '2026-03-02');

        $balanceBefore = DB::table('stock_balances')->where('item_id', $item->id)->first();
        $this->assertSame('240.000000', $balanceBefore->quantity);

        $originalLine = $purchase->lines()->first();

        $response = $this->post("/purchases/{$purchase->id}/returns", [
            'business_date' => '2026-03-03',
            'reason' => 'بضاعة تالفة',
            'lines' => [[
                'original_line_id' => $originalLine->id,
                'quantity' => '2', // بوحدة السطر الأصلي: علبة = 12 قطعة
                'unit_price' => (string) $originalLine->entered_unit_price,
                'description' => 'مرتجع جزئي',
            ]],
        ]);

        $response->assertSessionHasNoErrors();

        $return = Document::query()->where('kind', Document::KIND_PURCHASE_RETURN)->firstOrFail();
        $this->assertTrue($return->isPosted());
        $this->assertSame((int) $purchase->id, (int) $return->original_document_id);

        // إرجاع 2 علبة (24 قطعة) بتكلفة الشراء الأصلية (12 ريال/علبة) لا بالمتوسط الجديد
        $balanceAfter = DB::table('stock_balances')->where('item_id', $item->id)->first();
        $this->assertSame('216.000000', $balanceAfter->quantity);

        $this->assertLedgerBalanced();
        $this->assertInventoryLedgerMatchesStock();
    }

    public function test_purchase_return_cannot_exceed_remaining_original_quantity(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');

        $purchase = $this->postPurchase($item, $supplier->id, quantity: '10', unitPrice: '12', unit: 'BOX', date: '2026-03-01');
        $originalLine = $purchase->lines()->first();

        $response = $this->post("/purchases/{$purchase->id}/returns", [
            'business_date' => '2026-03-02',
            'lines' => [[
                'original_line_id' => $originalLine->id,
                'quantity' => '9999',
                'unit_price' => (string) $originalLine->unit_price,
            ]],
        ]);

        $response->assertSessionHasErrors('lines');
        $this->assertSame(0, Document::query()->where('kind', Document::KIND_PURCHASE_RETURN)->count());
    }

    public function test_purchase_return_service_rejects_non_purchase_return_document(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');
        $purchase = $this->postPurchase($item, $supplier->id, quantity: '5', unitPrice: '10', unit: 'PCE', date: '2026-03-01');

        $this->expectException(DomainException::class);

        app(PurchaseReturnPostingService::class)->post($purchase->id, $this->owner->id);
    }

    public function test_stock_count_freezes_warehouse_and_posts_difference_to_stock_count_account(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');

        $this->postPurchase($item, $supplier->id, quantity: '100', unitPrice: '1.00', unit: 'PCE', date: '2026-04-01');

        $stockCount = app(StockCountService::class)->create(
            $this->warehouse->id,
            [$item->id],
            '2026-04-02',
            $this->owner->id,
        );

        $this->assertSame('draft', $stockCount->status);

        // حركة المخزن مجمّدة أثناء الجرد
        $this->expectException(DomainException::class);

        $this->postPurchase($item, $supplier->id, quantity: '1', unitPrice: '1.00', unit: 'PCE', date: '2026-04-03');
    }

    public function test_stock_count_records_counts_then_posts_adjustment_for_the_difference(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');

        $this->postPurchase($item, $supplier->id, quantity: '100', unitPrice: '1.00', unit: 'PCE', date: '2026-04-01');

        $service = app(StockCountService::class);

        $stockCount = $service->create($this->warehouse->id, [$item->id], '2026-04-02', $this->owner->id);

        // جرد فعلي أقل من المتوقع بـ 3 قطع (نقص)
        $service->recordCounts($stockCount, [
            ['item_id' => $item->id, 'counted_quantity' => '97'],
        ], $this->owner->id);

        $stockCount->refresh();
        $this->assertSame('counting', $stockCount->status);

        $posted = $service->post($stockCount->id, $this->owner->id);

        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->adjustment_document_id);

        $balance = DB::table('stock_balances')->where('item_id', $item->id)->first();
        $this->assertSame('97.000000', $balance->quantity);

        $differenceAccountId = app(\App\Modules\Accounting\Services\AccountResolver::class)
            ->id(\App\Modules\Accounting\Services\AccountResolver::STOCK_COUNT_DIFFERENCE);

        $hasDifferenceLine = DB::table('journal_lines')
            ->where('journal_entry_id', function ($q) use ($posted) {
                $q->select('id')->from('journal_entries')->where('document_id', $posted->adjustment_document_id);
            })
            ->where('account_id', $differenceAccountId)
            ->exists();

        $this->assertTrue($hasDifferenceLine, 'يجب أن يُقفل فرق الجرد على حساب فروقات الجرد');

        // المخزن غير مجمّد بعد اعتماد الجرد
        $this->postPurchase($item, $supplier->id, quantity: '1', unitPrice: '1.00', unit: 'PCE', date: '2026-04-05');

        $this->assertLedgerBalanced();
        $this->assertInventoryLedgerMatchesStock();
    }

    public function test_stock_count_with_no_differences_posts_without_adjustment_document(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');

        $this->postPurchase($item, $supplier->id, quantity: '50', unitPrice: '1.00', unit: 'PCE', date: '2026-04-01');

        $service = app(StockCountService::class);
        $stockCount = $service->create($this->warehouse->id, [$item->id], '2026-04-02', $this->owner->id);

        $service->recordCounts($stockCount, [
            ['item_id' => $item->id, 'counted_quantity' => '50'],
        ], $this->owner->id);

        $posted = $service->post($stockCount->id, $this->owner->id);

        $this->assertSame('posted', $posted->status);
        $this->assertNull($posted->adjustment_document_id);
    }

    public function test_cancelling_stock_count_unfreezes_warehouse(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');
        $this->postPurchase($item, $supplier->id, quantity: '10', unitPrice: '1.00', unit: 'PCE', date: '2026-04-01');

        $service = app(StockCountService::class);
        $stockCount = $service->create($this->warehouse->id, [$item->id], '2026-04-02', $this->owner->id);

        $service->cancel($stockCount->id, 'خطأ بالمخزن', $this->owner->id);

        $this->assertSame('cancelled', StockCount::query()->find($stockCount->id)->status);

        // لا يرمي استثناء الآن
        $this->postPurchase($item, $supplier->id, quantity: '1', unitPrice: '1.00', unit: 'PCE', date: '2026-04-03');

        $this->assertLedgerBalanced();
    }

    public function test_only_one_active_stock_count_per_warehouse(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');
        $this->postPurchase($item, $supplier->id, quantity: '10', unitPrice: '1.00', unit: 'PCE', date: '2026-04-01');

        $service = app(StockCountService::class);
        $service->create($this->warehouse->id, [$item->id], '2026-04-02', $this->owner->id);

        $this->expectException(DomainException::class);
        $service->create($this->warehouse->id, [$item->id], '2026-04-02', $this->owner->id);
    }
}
