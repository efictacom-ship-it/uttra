<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Parties\Models\Party;
use App\Modules\Settings\Models\BusinessSetting;
use App\Modules\Settings\Models\TaxCode;
use App\Modules\Settings\Services\DefaultChartInstaller;
use App\Modules\Catalog\Services\CatalogService;

/** إعداد منشأة كاملة جاهزة للاختبارات: حسابات، ضريبة، وحدات، مخزن، فترة. */
trait SetsUpBusiness
{
    protected User $owner;

    protected Warehouse $warehouse;

    protected TaxCode $vat15;

    protected TaxCode $vatZero;

    protected function setUpBusiness(bool $vatRegistered = true): void
    {
        $this->owner = new User(['name' => 'المالك', 'email' => 'owner@test.local', 'role' => 'owner', 'is_active' => true]);
        $this->owner->setPassword('kalima-sirriya-2026');
        $this->owner->save();

        BusinessSetting::persist([
            'legal_name' => 'مؤسسة المكتبة',
            'trade_name' => 'مكتبة الطالب',
            'vat_registered' => $vatRegistered,
            'vat_number' => $vatRegistered ? '300000000000003' : null,
            'einvoice_phase' => $vatRegistered ? 'phase2' : 'none',
            'rounding_decimals' => 2,
            'rounding_mode' => 'half_up',
            'setup_completed_at' => now(),
        ]);

        app(DefaultChartInstaller::class)->install();

        $this->warehouse = Warehouse::query()->firstOrFail();

        AccountingPeriod::query()->create([
            'name' => 'السنة المالية 2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'status' => 'open',
        ]);

        $this->vat15 = TaxCode::query()->create([
            'code' => 'VAT15', 'name' => 'ضريبة 15%', 'category' => 'S', 'rate' => '15.0000',
            'valid_from' => '2020-07-01', 'is_active' => true,
        ]);

        $this->vatZero = TaxCode::query()->create([
            'code' => 'EXEMPT', 'name' => 'معفى', 'category' => 'E', 'rate' => '0.0000',
            'exemption_reason_code' => 'VATEX-SA-EDU', 'exemption_reason' => 'خدمة معفاة',
            'valid_from' => '2020-07-01', 'is_active' => true,
        ]);

        $this->actingAs($this->owner);
    }

    protected function unit(string $code): Unit
    {
        return Unit::query()->where('code', $code)->firstOrFail();
    }

    protected function financialAccount(string $kind): FinancialAccount
    {
        return FinancialAccount::query()->where('kind', $kind)->firstOrFail();
    }

    protected function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    /** صنف مخزني بوحدتين: قطعة (أساسية) وعلبة = 12 قطعة. */
    protected function makeStockItem(string $sku = 'PEN-1', string $piecePrice = '2.00', string $boxPrice = '22.00'): Item
    {
        return app(CatalogService::class)->create([
            'sku' => $sku,
            'name_ar' => 'قلم جاف أزرق',
            'item_type' => 'stock',
            'base_unit_id' => $this->unit('PCE')->id,
            'tax_code_id' => $this->vat15->id,
            'reorder_level' => '20',
            'is_active' => true,
            'units' => [
                ['unit_id' => $this->unit('PCE')->id, 'to_base_factor' => '1', 'sale_price' => $piecePrice, 'price_includes_tax' => true, 'barcodes' => [$this->barcodeFor($sku, 1)]],
                ['unit_id' => $this->unit('BOX')->id, 'to_base_factor' => '12', 'sale_price' => $boxPrice, 'price_includes_tax' => true, 'barcodes' => [$this->barcodeFor($sku, 2)]],
            ],
        ]);
    }

    protected function makeServiceItem(string $sku = 'COPY-BW', string $price = '0.25'): Item
    {
        return app(CatalogService::class)->create([
            'sku' => $sku,
            'name_ar' => 'تصوير أبيض وأسود',
            'item_type' => 'service',
            'base_unit_id' => $this->unit('SIDE')->id,
            'tax_code_id' => $this->vat15->id,
            'is_active' => true,
            'units' => [
                ['unit_id' => $this->unit('SIDE')->id, 'to_base_factor' => '1', 'sale_price' => $price, 'price_includes_tax' => true],
            ],
        ]);
    }

    protected function makeParty(bool $customer = true, bool $supplier = false, string $name = 'عميل تجريبي'): Party
    {
        return Party::query()->create([
            'name' => $name,
            'is_customer' => $customer,
            'is_supplier' => $supplier,
            'is_active' => true,
        ]);
    }

    protected function barcodeFor(string $sku, int $index): string
    {
        return '628'.substr(md5($sku.$index), 0, 9);
    }

    protected function itemUnitId(Item $item, string $unitCode): int
    {
        return (int) $item->units()->where('unit_id', $this->unit($unitCode)->id)->value('id');
    }

    protected function draft(string $kind, array $payload): \App\Shared\Documents\Models\Document
    {
        return app(\App\Shared\Documents\Services\DocumentDraftService::class)->create($kind, $payload, $this->owner->id);
    }

    protected function postPurchase(Item $item, int $supplierId, string $quantity, string $unitPrice, string $unit = 'PCE', string $date = '2026-02-01'): \App\Shared\Documents\Models\Document
    {
        $document = $this->draft(\App\Shared\Documents\Models\Document::KIND_PURCHASE, [
            'party_id' => $supplierId,
            'warehouse_id' => $this->warehouse->id,
            'business_date' => $date,
            'lines' => [[
                'item_id' => $item->id,
                'item_unit_id' => $this->itemUnitId($item, $unit),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'price_includes_tax' => false,
                'description' => $item->name_ar,
            ]],
        ]);

        return app(\App\Modules\Purchasing\Services\PurchasePostingService::class)->post($document->id, $this->owner->id);
    }

    /** مجموع المدين = مجموع الدائن في كل القيود المرحلة. */
    protected function assertLedgerBalanced(): void
    {
        $row = \Illuminate\Support\Facades\DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->selectRaw('COALESCE(SUM(debit),0) AS debit, COALESCE(SUM(credit),0) AS credit')
            ->first();

        $this->assertSame(
            number_format((float) $row->debit, 2, '.', ''),
            number_format((float) $row->credit, 2, '.', ''),
            'مجموع المدين يجب أن يساوي مجموع الدائن'
        );

        $unbalanced = \Illuminate\Support\Facades\DB::table('journal_lines')
            ->select('journal_entry_id')
            ->groupBy('journal_entry_id')
            ->havingRaw('ABS(SUM(debit) - SUM(credit)) > 0.0000005')
            ->get();

        $this->assertCount(0, $unbalanced, 'كل قيد على حدة يجب أن يكون متوازنًا');
    }

    /** رصيد حساب المخزون في الأستاذ = قيمة المخزون في الأرصدة = مجموع حركات القيمة. */
    protected function assertInventoryLedgerMatchesStock(): void
    {
        $accountId = app(\App\Modules\Accounting\Services\AccountResolver::class)
            ->id(\App\Modules\Accounting\Services\AccountResolver::INVENTORY);

        $ledger = \Illuminate\Support\Facades\DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_lines.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit - credit),0) AS net')
            ->value('net');

        $balances = \Illuminate\Support\Facades\DB::table('stock_balances')->sum('inventory_value');
        $movements = \Illuminate\Support\Facades\DB::table('stock_movements')->sum('value_delta');

        $this->assertSame(
            number_format((float) $ledger, 2, '.', ''),
            number_format((float) $balances, 2, '.', ''),
            'حساب المخزون في الأستاذ يجب أن يطابق قيمة الأرصدة'
        );

        $this->assertSame(
            number_format((float) $movements, 2, '.', ''),
            number_format((float) $balances, 2, '.', ''),
            'مجموع value_delta يجب أن يطابق قيمة الأرصدة'
        );
    }
}
