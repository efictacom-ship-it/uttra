<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\EInvoicing\Contracts\EInvoiceSubmissionResult;
use App\Modules\EInvoicing\Models\EinvoiceDevice;
use App\Modules\EInvoicing\Models\EinvoiceDocument;
use App\Modules\EInvoicing\Providers\MockEInvoicingProvider;
use App\Modules\EInvoicing\Services\EInvoiceGenerationService;
use App\Modules\EInvoicing\Services\EInvoiceOutboxProcessor;
use App\Modules\EInvoicing\Services\EInvoiceSubmissionService;
use App\Shared\Documents\Models\Document;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsUpBusiness;
use Tests\TestCase;

class EInvoicingTest extends TestCase
{
    use RefreshDatabase, SetsUpBusiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
    }

    protected function tearDown(): void
    {
        MockEInvoicingProvider::resetForcedOutcome();
        parent::tearDown();
    }

    private function device(): EinvoiceDevice
    {
        return EinvoiceDevice::query()->create([
            'serial_number' => 'MOCK-DEVICE-1',
            'environment' => 'simulation',
            'is_active' => true,
        ]);
    }

    private function postedSale(): Document
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد');
        $this->postPurchase($item, $supplier->id, quantity: '20', unitPrice: '1.00', unit: 'PCE', date: '2026-08-01');

        $sale = $this->draft(Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-08-02',
            'einvoice_type' => 'simplified',
            'lines' => [[
                'item_id' => $item->id,
                'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '5',
                'unit_price' => '2.30',
                'price_includes_tax' => true,
                'description' => 'بيع مفوتر إلكترونيًا',
            ]],
        ]);

        return app(\App\Modules\Sales\Services\SalesPostingService::class)->post($sale->id, [[
            'method' => 'cash',
            'financial_account_id' => $this->financialAccount('cash')->id,
            'amount' => $sale->grand_total,
        ]], $this->owner->id);
    }

    public function test_generate_creates_reporting_document_with_outbox_job_for_simplified_invoice(): void
    {
        $sale = $this->postedSale();
        $device = $this->device();

        $einvoiceDocument = app(EInvoiceGenerationService::class)->generate($sale->id, $device->id);

        $this->assertSame('reporting', $einvoiceDocument->route);
        $this->assertSame('ready', $einvoiceDocument->status);
        $this->assertNotNull($einvoiceDocument->reporting_due_at);

        $this->assertSame(1, DB::table('outbox_jobs')->where('einvoice_document_id', $einvoiceDocument->id)->count());

        $device->refresh();
        $this->assertSame(2, (int) $device->next_invoice_counter);
    }

    public function test_generate_is_idempotent_per_document(): void
    {
        $sale = $this->postedSale();
        $device = $this->device();

        $first = app(EInvoiceGenerationService::class)->generate($sale->id, $device->id);
        $second = app(EInvoiceGenerationService::class)->generate($sale->id, $device->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, EinvoiceDocument::query()->where('document_id', $sale->id)->count());
    }

    public function test_generate_rejects_document_without_einvoice_type(): void
    {
        $item = $this->makeStockItem();
        $supplier = $this->makeParty(false, true, 'مورد آخر');
        $this->postPurchase($item, $supplier->id, quantity: '10', unitPrice: '1.00', unit: 'PCE', date: '2026-08-01');

        $sale = $this->draft(Document::KIND_SALE, [
            'warehouse_id' => $this->warehouse->id,
            'business_date' => '2026-08-02',
            'lines' => [[
                'item_id' => $item->id,
                'item_unit_id' => $this->itemUnitId($item, 'PCE'),
                'quantity' => '1',
                'unit_price' => '2.30',
                'price_includes_tax' => true,
                'description' => 'بيع بلا فوترة إلكترونية',
            ]],
        ]);

        $posted = app(\App\Modules\Sales\Services\SalesPostingService::class)->post($sale->id, [[
            'method' => 'cash',
            'financial_account_id' => $this->financialAccount('cash')->id,
            'amount' => $sale->grand_total,
        ]], $this->owner->id);

        $this->expectException(DomainException::class);
        app(EInvoiceGenerationService::class)->generate($posted->id, $this->device()->id);
    }

    public function test_mock_provider_accepts_and_moves_status_to_reported(): void
    {
        $sale = $this->postedSale();
        $einvoiceDocument = app(EInvoiceGenerationService::class)->generate($sale->id, $this->device()->id);

        $updated = app(EInvoiceSubmissionService::class)->submit($einvoiceDocument->id);

        $this->assertSame('reported', $updated->status);
        $this->assertSame(1, $updated->submissions()->count());
        $this->assertSame('accepted', $updated->submissions()->first()->result);
    }

    public function test_rejected_submission_does_not_touch_the_commercial_document(): void
    {
        MockEInvoicingProvider::forceOutcome(fn () => EInvoiceSubmissionResult::rejected(['error' => 'INVALID_TAX_NUMBER']));

        $sale = $this->postedSale();
        $einvoiceDocument = app(EInvoiceGenerationService::class)->generate($sale->id, $this->device()->id);

        $updated = app(EInvoiceSubmissionService::class)->submit($einvoiceDocument->id);

        $this->assertSame('rejected', $updated->status);

        // المستند التجاري نفسه غير متأثر: لا يزال مرحّلاً كما هو
        $this->assertTrue($sale->fresh()->isPosted());
    }

    public function test_unknown_result_never_becomes_rejected_and_can_be_retried(): void
    {
        MockEInvoicingProvider::forceOutcome(fn () => EInvoiceSubmissionResult::connectionError('انقطاع مؤقت'));

        $sale = $this->postedSale();
        $einvoiceDocument = app(EInvoiceGenerationService::class)->generate($sale->id, $this->device()->id);

        $afterFirst = app(EInvoiceSubmissionService::class)->submit($einvoiceDocument->id);
        $this->assertSame('connection_error', $afterFirst->status);
        $this->assertNotSame('rejected', $afterFirst->status);

        MockEInvoicingProvider::resetForcedOutcome();

        $afterRetry = app(EInvoiceSubmissionService::class)->retry($einvoiceDocument->id);
        $this->assertSame('reported', $afterRetry->status);
        $this->assertSame(2, $afterRetry->submissions()->count());
    }

    public function test_retry_reuses_the_same_einvoice_document_and_original_document_id(): void
    {
        MockEInvoicingProvider::forceOutcome(fn () => EInvoiceSubmissionResult::rejected(['error' => 'x']));

        $sale = $this->postedSale();
        $einvoiceDocument = app(EInvoiceGenerationService::class)->generate($sale->id, $this->device()->id);
        app(EInvoiceSubmissionService::class)->submit($einvoiceDocument->id);

        MockEInvoicingProvider::resetForcedOutcome();

        $retried = app(EInvoiceSubmissionService::class)->retry($einvoiceDocument->id);

        $this->assertSame($einvoiceDocument->id, $retried->id);
        $this->assertSame($sale->id, $retried->document_id);
    }

    public function test_outbox_processor_processes_pending_jobs(): void
    {
        $sale = $this->postedSale();
        app(EInvoiceGenerationService::class)->generate($sale->id, $this->device()->id);

        $result = app(EInvoiceOutboxProcessor::class)->processPending();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, DB::table('outbox_jobs')->where('status', 'pending')->count());
    }
}
