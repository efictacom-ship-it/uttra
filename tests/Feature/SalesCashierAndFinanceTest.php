<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CashSessionService;
use App\Shared\Documents\Models\Document;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsUpBusiness;
use Tests\TestCase;

class SalesCashierAndFinanceTest extends TestCase
{
    use RefreshDatabase, SetsUpBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_cashier_creates_cart_posts_sale_and_can_return_part_of_it(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');
        $this->postPurchase($item, $supplier->id, quantity: '50', unitPrice: '1.00', unit: 'PCE', date: '2026-05-01');

        $cart = $this->post('/sales', [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-05-02',
            'lines' => [[
                'item_id' => $item->id,
                'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '10',
                'unit_price' => '2.30',
                'price_includes_tax' => true,
                'description' => 'قلم جاف - قطعة',
            ]],
        ]);

        $cart->assertSessionHasNoErrors();

        $sale = Document::query()->where('kind', Document::KIND_SALE)->firstOrFail();
        $this->assertTrue($sale->isDraft());

        $postResponse = $this->post("/sales/{$sale->id}/post", [
            'payments' => [[
                'method' => 'cash',
                'financial_account_id' => $this->financialAccount('cash')->id,
                'amount' => $sale->grand_total,
            ]],
        ]);

        $postResponse->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertTrue($sale->isPosted());

        $balance = DB::table('stock_balances')->where('item_id', $item->id)->first();
        $this->assertSame('40.000000', $balance->quantity);

        $originalLine = $sale->lines()->first();

        $returnResponse = $this->post("/sales/{$sale->id}/returns", [
            'business_date' => '2026-05-03',
            'reason' => 'عيب تصنيع',
            'lines' => [[
                'original_line_id' => $originalLine->id,
                'quantity' => '3',
                'unit_price' => (string) $originalLine->entered_unit_price,
            ]],
            'refund' => [
                'amount' => '6.90',
                'method' => 'cash',
                'financial_account_id' => $this->financialAccount('cash')->id,
            ],
        ]);

        $returnResponse->assertSessionHasNoErrors();

        $return = Document::query()->where('kind', Document::KIND_SALE_RETURN)->firstOrFail();
        $this->assertTrue($return->isPosted());

        $balanceAfterReturn = DB::table('stock_balances')->where('item_id', $item->id)->first();
        $this->assertSame('43.000000', $balanceAfterReturn->quantity);

        $this->assertLedgerBalanced();
        $this->assertInventoryLedgerMatchesStock();
    }

    public function test_cash_session_open_close_and_expected_cash_matches_receipts(): void
    {
        $cashAccount = $this->financialAccount('cash');

        $service = app(CashSessionService::class);
        $session = $service->open($cashAccount->id, '100', $this->owner->id);

        $this->assertTrue($session->isOpen());

        $customer = $this->makeParty(true, false, 'عميل');

        app(\App\Modules\Finance\Services\PaymentPostingService::class)->postStandalone([
            'direction' => 'receipt',
            'method' => 'cash',
            'financial_account_id' => $cashAccount->id,
            'party_id' => $customer->id,
            'amount' => '50',
            'payment_date' => '2026-05-01',
            'notes' => 'دفعة مقدمة',
        ], [], $this->owner->id);

        $expected = $service->expectedCash($session->fresh());
        $this->assertSame('150.000000', $expected->toDb());

        $closed = $service->close($session->id, '150', null, $this->owner->id);

        $this->assertFalse($closed->isOpen());
        $this->assertSame('150.000000', $closed->expected_cash);

        $this->assertLedgerBalanced();
    }

    public function test_cash_session_close_with_difference_requires_reason(): void
    {
        $cashAccount = $this->financialAccount('cash');
        $service = app(CashSessionService::class);
        $session = $service->open($cashAccount->id, '100', $this->owner->id);

        $this->expectException(DomainException::class);
        $service->close($session->id, '90', null, $this->owner->id);
    }

    public function test_only_one_open_cash_session_per_financial_account(): void
    {
        $cashAccount = $this->financialAccount('cash');
        $service = app(CashSessionService::class);
        $service->open($cashAccount->id, '100', $this->owner->id);

        $this->expectException(DomainException::class);
        $service->open($cashAccount->id, '50', $this->owner->id);
    }

    public function test_standalone_payment_and_transfer_between_financial_accounts(): void
    {
        $customer = $this->makeParty(true, false, 'عميل نقدي');

        $response = $this->post('/payments', [
            'direction' => 'receipt',
            'method' => 'cash',
            'financial_account_id' => $this->financialAccount('cash')->id,
            'party_id' => $customer->id,
            'amount' => '200',
            'payment_date' => '2026-05-01',
            'notes' => 'دفعة مقدمة من العميل',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::query()->count());

        $bank = $this->financialAccount('bank');
        $cash = $this->financialAccount('cash');

        $transferResponse = $this->post('/payments/transfer', [
            'financial_account_id' => $cash->id,
            'destination_financial_account_id' => $bank->id,
            'amount' => '150',
            'payment_date' => '2026-05-02',
        ]);

        $transferResponse->assertSessionHasNoErrors();

        $this->assertSame(2, Payment::query()->count());
        $this->assertLedgerBalanced();
    }
}
