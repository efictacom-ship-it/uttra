<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Services\PeriodService;
use App\Shared\Documents\Models\Document;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsUpBusiness;
use Tests\TestCase;

class ExpensesAndPeriodsTest extends TestCase
{
    use RefreshDatabase, SetsUpBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    public function test_expense_posts_with_recoverable_tax_and_immediate_payment(): void
    {
        $expenseAccount = \App\Modules\Accounting\Models\Account::query()
            ->where('account_type', 'expense')->where('allow_posting', true)->firstOrFail();

        $response = $this->post('/expenses', [
            'business_date' => '2026-06-01',
            'notes' => 'فاتورة كهرباء',
            'lines' => [[
                'account_id' => $expenseAccount->id,
                'description' => 'فاتورة كهرباء يونيو',
                'unit_price' => '115',
                'price_includes_tax' => true,
            ]],
            'payment' => [
                'amount' => '115',
                'method' => 'cash',
                'financial_account_id' => $this->financialAccount('cash')->id,
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $expense = Document::query()->where('kind', Document::KIND_EXPENSE)->firstOrFail();
        $this->assertTrue($expense->isPosted());
        $this->assertSame('115.000000', $expense->grand_total);

        $outstanding = app(\App\Modules\Finance\Services\PaymentAllocationService::class)->outstanding($expense);
        $this->assertTrue($outstanding->isZero(), 'يجب أن يكون المصروف مسددًا بالكامل بعد الدفعة الفورية');

        $this->assertLedgerBalanced();
    }

    public function test_period_close_blocks_posting_and_reopen_restores_it(): void
    {
        $period = AccountingPeriod::query()->where('status', 'open')->firstOrFail();

        app(PeriodService::class)->close($period, $this->owner->id);

        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');

        $this->expectException(DomainException::class);
        $this->postPurchase($item, $supplier->id, quantity: '1', unitPrice: '1', unit: 'PCE', date: '2026-02-15');
    }

    public function test_reopening_a_closed_period_allows_posting_again(): void
    {
        $period = AccountingPeriod::query()->where('status', 'open')->firstOrFail();
        $service = app(PeriodService::class);

        $service->close($period, $this->owner->id);
        $service->reopen($period->fresh());

        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');

        $document = $this->postPurchase($item, $supplier->id, quantity: '1', unitPrice: '1', unit: 'PCE', date: '2026-02-15');
        $this->assertTrue($document->isPosted());
    }

    public function test_creating_a_new_fiscal_year_rejects_overlap(): void
    {
        $service = app(PeriodService::class);

        $this->expectException(DomainException::class);
        $service->createYear(2026);
    }

    public function test_closing_a_period_with_draft_documents_inside_it_is_blocked(): void
    {
        $item = $this->makeStockItem();

        // مسودة فاتورة شراء دون ترحيل، بتاريخ ضمن الفترة المفتوحة
        $this->draft(Document::KIND_PURCHASE, [
            'party_id' => $this->makeParty(false, true, 'مورد')->id,
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-03-01',
            'lines' => [[
                'item_id' => $item->id,
                'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '1',
                'unit_price' => '1',
                'price_includes_tax' => false,
                'description' => 'مسودة معلّقة',
            ]],
        ]);

        $period = AccountingPeriod::query()->where('status', 'open')->firstOrFail();

        $this->expectException(DomainException::class);
        app(PeriodService::class)->close($period, $this->owner->id);
    }

    public function test_closing_a_period_succeeds_once_the_draft_is_posted_or_removed(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');

        // مسودة تُرحَّل قبل الإقفال فلا تعود عائقًا
        $this->postPurchase($item, $supplier->id, quantity: '1', unitPrice: '1', unit: 'PCE', date: '2026-03-01');

        $period = AccountingPeriod::query()->where('status', 'open')->firstOrFail();

        app(PeriodService::class)->close($period, $this->owner->id);

        $this->assertSame('closed', $period->fresh()->status);
    }
}
