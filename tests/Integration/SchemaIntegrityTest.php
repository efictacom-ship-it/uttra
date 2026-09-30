<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تتحقق من أن قيود القاعدة تعمل فعليًا على MySQL، لا في الكود فقط.
 */
class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_design_tables_exist_with_foreign_keys_and_checks(): void
    {
        $schema = DB::connection()->getDatabaseName();

        $tables = DB::table('information_schema.tables')->where('table_schema', $schema)->count();
        $fks = DB::table('information_schema.table_constraints')
            ->where('table_schema', $schema)->where('constraint_type', 'FOREIGN KEY')->count();
        $checks = DB::table('information_schema.check_constraints')->where('constraint_schema', $schema)->count();

        $this->assertGreaterThanOrEqual(34, $tables, 'جداول التصميم الـ34 يجب أن تكون موجودة');
        $this->assertGreaterThanOrEqual(75, $fks, 'مفاتيح العلاقات يجب أن تُنشأ فعليًا');
        $this->assertGreaterThanOrEqual(33, $checks, 'قيود CHECK يجب أن تكون فعالة على MySQL 8');
    }

    public function test_business_settings_is_single_row(): void
    {
        $this->expectExceptionMessageMatches('/Check constraint/i');

        DB::table('business_settings')->insert(['id' => 2, 'legal_name' => 'منشأة ثانية']);
    }

    public function test_negative_stock_balance_is_rejected_by_database(): void
    {
        [$warehouseId, $itemId] = $this->makeItem();

        $this->expectExceptionMessageMatches('/Check constraint/i');

        DB::table('stock_balances')->insert([
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'quantity' => -1,
            'inventory_value' => 0,
            'average_cost' => 0,
        ]);
    }

    public function test_tax_rate_above_100_is_rejected(): void
    {
        $this->expectExceptionMessageMatches('/Check constraint/i');

        DB::table('tax_codes')->insert([
            'code' => 'BAD', 'name' => 'خطأ', 'category' => 'S', 'rate' => 150, 'valid_from' => '2026-01-01',
        ]);
    }

    public function test_document_line_cannot_use_unit_of_another_item(): void
    {
        [$warehouseId, $itemId, $itemUnitId, $userId] = $this->makeItem(true);

        $otherItemId = DB::table('items')->insertGetId([
            'sku' => 'OTHER-1', 'name_ar' => 'صنف آخر', 'item_type' => 'stock',
            'base_unit_id' => DB::table('units')->value('id'),
        ]);

        $documentId = DB::table('documents')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'kind' => 'sale', 'business_date' => '2026-01-05',
            'idempotency_key' => 'k-'.uniqid(), 'created_by' => $userId, 'warehouse_id' => $warehouseId,
        ]);

        $this->expectExceptionMessageMatches('/foreign key constraint/i');

        DB::table('document_lines')->insert([
            'document_id' => $documentId, 'line_number' => 1,
            'item_id' => $otherItemId, 'item_unit_id' => $itemUnitId,
            'description' => 'وحدة صنف آخر', 'item_type_snapshot' => 'stock',
            'unit_name_snapshot' => 'قطعة', 'quantity' => 1, 'base_quantity' => 1,
        ]);
    }

    public function test_journal_line_cannot_hold_both_debit_and_credit(): void
    {
        $userId = $this->makeUser();
        $accountId = DB::table('accounts')->insertGetId(['code' => '9999', 'name' => 'تجريبي', 'account_type' => 'asset']);
        $periodId = DB::table('accounting_periods')->insertGetId([
            'name' => 'فترة', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        ]);
        $entryId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JV-1', 'accounting_period_id' => $periodId, 'entry_date' => '2026-01-05',
            'description' => 'اختبار', 'created_by' => $userId,
        ]);

        $this->expectExceptionMessageMatches('/Check constraint/i');

        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entryId, 'line_number' => 1, 'account_id' => $accountId,
            'debit' => 10, 'credit' => 10,
        ]);
    }

    public function test_only_one_open_cash_session_per_financial_account(): void
    {
        $userId = $this->makeUser();
        $accountId = DB::table('accounts')->insertGetId(['code' => '1101', 'name' => 'الصندوق', 'account_type' => 'asset']);
        $financialAccountId = DB::table('financial_accounts')->insertGetId([
            'name' => 'الصندوق', 'kind' => 'cash', 'ledger_account_id' => $accountId,
        ]);

        DB::table('cash_sessions')->insert([
            'financial_account_id' => $financialAccountId, 'opened_by' => $userId,
            'opened_at' => now(), 'opening_cash' => 0,
        ]);

        $this->expectExceptionMessageMatches('/Duplicate entry/i');

        DB::table('cash_sessions')->insert([
            'financial_account_id' => $financialAccountId, 'opened_by' => $userId,
            'opened_at' => now(), 'opening_cash' => 0,
        ]);
    }

    /** @return array{0:int,1:int,2?:int,3?:int} */
    private function makeItem(bool $withUnit = false): array
    {
        $userId = $this->makeUser();
        $warehouseId = DB::table('warehouses')->insertGetId(['code' => 'MAIN', 'name' => 'مخزن']);
        $unitId = DB::table('units')->insertGetId(['code' => 'PCE', 'name' => 'قطعة']);
        $itemId = DB::table('items')->insertGetId([
            'sku' => 'SKU-1', 'name_ar' => 'قلم', 'item_type' => 'stock', 'base_unit_id' => $unitId,
        ]);

        if (! $withUnit) {
            return [$warehouseId, $itemId];
        }

        $itemUnitId = DB::table('item_units')->insertGetId([
            'item_id' => $itemId, 'unit_id' => $unitId, 'to_base_factor' => 1, 'sale_price' => 2,
        ]);

        return [$warehouseId, $itemId, $itemUnitId, $userId];
    }

    private function makeUser(): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'مستخدم', 'email' => 'u'.uniqid().'@test.local', 'password_hash' => bcrypt('secret'),
        ]);
    }
}
