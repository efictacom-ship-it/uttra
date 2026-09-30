<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Reports\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsUpBusiness;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase, SetsUpBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    private function seedSaleAndPurchase(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد التقارير');
        $this->postPurchase($item, $supplier->id, quantity: '100', unitPrice: '1.00', unit: 'PCE', date: '2026-07-01');

        $sale = $this->draft(\App\Shared\Documents\Models\Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-07-02',
            'lines' => [[
                'item_id' => $item->id,
                'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '10',
                'unit_price' => '2.30',
                'price_includes_tax' => true,
                'description' => 'بيع تقرير',
            ]],
        ]);

        app(\App\Modules\Sales\Services\SalesPostingService::class)->post($sale->id, [[
            'method' => 'cash',
            'financial_account_id' => $this->financialAccount('cash')->id,
            'amount' => $sale->grand_total,
        ]], $this->owner->id);
    }

    public function test_trial_balance_is_balanced_and_matches_ledger(): void
    {
        $this->seedSaleAndPurchase();

        $rows = app(ReportService::class)->trialBalance('2026-07-01', '2026-07-02');

        $debit = array_sum(array_map(fn ($r) => (float) $r['debit'], $rows));
        $credit = array_sum(array_map(fn ($r) => (float) $r['credit'], $rows));

        $this->assertEqualsWithDelta($debit, $credit, 0.01, 'ميزان المراجعة يجب أن يتساوى فيه المدين والدائن');
        $this->assertLedgerBalanced();
    }

    public function test_sales_report_route_returns_ok_with_expected_totals(): void
    {
        $this->seedSaleAndPurchase();

        $response = $this->get('/reports/sales?from=2026-07-01&to=2026-07-02');
        $response->assertOk();

        $summary = app(ReportService::class)->salesSummary('2026-07-01', '2026-07-02');
        $this->assertNotEmpty($summary);
        $this->assertSame(1, (int) $summary[0]['invoices']);
    }

    public function test_profit_and_loss_net_profit_equals_revenue_minus_expense(): void
    {
        $this->seedSaleAndPurchase();

        $report = app(ReportService::class)->profitAndLoss('2026-07-01', '2026-07-02');

        $this->assertEqualsWithDelta(
            (float) $report['revenue_total'] - (float) $report['expense_total'],
            (float) $report['net_profit'],
            0.01
        );
    }

    public function test_vat_report_matches_document_tax_totals(): void
    {
        $this->seedSaleAndPurchase();

        $report = app(ReportService::class)->vatReport('2026-07-01', '2026-07-02');

        // 10 قطع × 2.30 شامل 15% ⇒ ضريبة مخرجات 3.00 تقريبًا
        $this->assertEqualsWithDelta(3.0, (float) $report['output_vat'], 0.05);
        $this->assertTrue((float) $report['input_vat_recoverable'] >= 0);
    }

    public function test_aging_report_lists_outstanding_sale_as_current_bucket(): void
    {
        $item = $this->makeStockItem();
        $customer = $this->makeParty(true, false, 'عميل آجل');
        $this->postPurchase($item, $this->makeParty(false, true, 'مورد')->id, quantity: '20', unitPrice: '1.00', unit: 'PCE', date: '2026-07-01');

        $sale = $this->draft(\App\Shared\Documents\Models\Document::KIND_SALE, [
            'party_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-07-02',
            'due_date' => '2026-08-02',
            'lines' => [[
                'item_id' => $item->id,
                'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '5',
                'unit_price' => '2.00',
                'price_includes_tax' => false,
                'description' => 'بيع آجل',
            ]],
        ]);

        app(\App\Modules\Sales\Services\SalesPostingService::class)->post($sale->id, [], $this->owner->id);

        $report = app(ReportService::class)->agingReport('receivable', '2026-07-15');

        $this->assertSame(1, count($report['documents']));
        $this->assertSame('current', $report['documents'][0]['bucket']);
    }

    public function test_stock_valuation_matches_stock_balances(): void
    {
        $this->seedSaleAndPurchase();

        $rows = app(ReportService::class)->stockValuation();
        $this->assertNotEmpty($rows);
        $this->assertSame('90.000000', $rows[0]['quantity']);
    }
}
