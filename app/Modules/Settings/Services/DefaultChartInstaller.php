<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * تثبيت دليل حسابات ووحدات وحسابات مالية افتراضية، وربطها بحسابات التشغيل.
 * إجراء إعداد لا يحتوي أي سر أو كلمة مرور، وقابل للتشغيل مرة بعد مرة بلا تكرار.
 * نسب الضريبة والتصنيفات لا تُفرض هنا: المحاسب يعرّفها من شاشة الضرائب.
 */
final class DefaultChartInstaller
{
    /** @var list<array{code:string,name:string,type:string,posting:bool,parent:?string}> */
    private const ACCOUNTS = [
        ['1', 'الأصول', 'asset', false, null],
        ['11', 'الأصول المتداولة', 'asset', false, '1'],
        ['1101', 'الصندوق', 'asset', true, '11'],
        ['1102', 'البنك', 'asset', true, '11'],
        ['1103', 'مبيعات الشبكة تحت التسوية', 'asset', true, '11'],
        ['1104', 'العملاء (مدينون)', 'asset', true, '11'],
        ['1105', 'حساب تحكم مبيعات الكاشير', 'asset', true, '11'],
        ['1106', 'دفعات موردين مقدمة', 'asset', true, '11'],
        ['1120', 'المخزون', 'asset', true, '11'],
        ['1130', 'ضريبة القيمة المضافة - مدخلات قابلة للخصم', 'asset', true, '11'],
        ['2', 'الالتزامات', 'liability', false, null],
        ['2101', 'الموردون (دائنون)', 'liability', true, '2'],
        ['2102', 'مصروفات مستحقة', 'liability', true, '2'],
        ['2103', 'دفعات عملاء مقدمة', 'liability', true, '2'],
        ['2130', 'ضريبة القيمة المضافة - مخرجات', 'liability', true, '2'],
        ['3', 'حقوق الملكية', 'equity', false, null],
        ['3101', 'رأس المال / حقوق المالك', 'equity', true, '3'],
        ['3102', 'مسحوبات المالك', 'equity', true, '3'],
        ['3900', 'حساب الأرصدة الافتتاحية', 'equity', true, '3'],
        ['4', 'الإيرادات', 'revenue', false, null],
        ['4101', 'إيراد بيع القرطاسية', 'revenue', true, '4'],
        ['4102', 'إيراد الخدمات الطلابية', 'revenue', true, '4'],
        ['5', 'التكاليف والمصروفات', 'expense', false, null],
        ['5101', 'تكلفة البضاعة المباعة', 'expense', true, '5'],
        ['5102', 'تكلفة خامات الخدمات', 'expense', true, '5'],
        ['5201', 'التلف والفقد', 'expense', true, '5'],
        ['5202', 'فروقات الجرد', 'expense', true, '5'],
        ['5203', 'فرق تكلفة مرتجع الشراء', 'expense', true, '5'],
        ['5204', 'فروق التقريب', 'expense', true, '5'],
        ['5301', 'رسوم تسوية الشبكة', 'expense', true, '5'],
        ['5302', 'إيجار', 'expense', true, '5'],
        ['5303', 'كهرباء وماء', 'expense', true, '5'],
        ['5304', 'اتصالات وإنترنت', 'expense', true, '5'],
        ['5305', 'صيانة أجهزة', 'expense', true, '5'],
        ['5306', 'مصروفات متنوعة', 'expense', true, '5'],
    ];

    /** @var array<string,string> ربط حسابات التشغيل بأكواد الدليل الافتراضي */
    private const MAPPING = [
        AccountResolver::SALES_REVENUE => '4101',
        AccountResolver::SERVICE_REVENUE => '4102',
        AccountResolver::VAT_OUTPUT => '2130',
        AccountResolver::VAT_INPUT_RECOVERABLE => '1130',
        AccountResolver::INVENTORY => '1120',
        AccountResolver::COST_OF_GOODS_SOLD => '5101',
        AccountResolver::MATERIAL_CONSUMPTION => '5102',
        AccountResolver::POS_SALES_CONTROL => '1105',
        AccountResolver::ACCOUNTS_RECEIVABLE => '1104',
        AccountResolver::ACCOUNTS_PAYABLE => '2101',
        AccountResolver::CUSTOMER_ADVANCES => '2103',
        AccountResolver::SUPPLIER_ADVANCES => '1106',
        AccountResolver::ROUNDING_DIFFERENCE => '5204',
        AccountResolver::INVENTORY_SHRINKAGE => '5201',
        AccountResolver::STOCK_COUNT_DIFFERENCE => '5202',
        AccountResolver::PURCHASE_RETURN_COST_DIFFERENCE => '5203',
        AccountResolver::CARD_SETTLEMENT_FEE => '5301',
        AccountResolver::OWNER_EQUITY => '3101',
        AccountResolver::OPENING_BALANCE => '3900',
    ];

    /** وحدات شائعة في مكتبة قرطاسية وخدمات طلابية. einvoice_code وفق UN/ECE Rec 20. */
    private const UNITS = [
        ['PCE', 'قطعة', 'H87'],
        ['BOX', 'علبة', 'BX'],
        ['CTN', 'كرتون', 'CT'],
        ['PK', 'باكيت', 'PK'],
        ['RM', 'رزمة ورق', 'RM'],
        ['SHT', 'ورقة', 'ST'],
        ['SIDE', 'وجه مطبوع', 'H87'],
        ['COPY', 'نسخة', 'H87'],
        ['SRV', 'خدمة', 'E48'],
        ['KG', 'كيلوجرام', 'KGM'],
        ['MTR', 'متر', 'MTR'],
    ];

    public function install(): void
    {
        DB::transaction(function (): void {
            $ids = [];
            foreach (self::ACCOUNTS as [$code, $name, $type, $posting, $parent]) {
                $account = Account::query()->firstOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'account_type' => $type,
                        'allow_posting' => $posting,
                        'parent_id' => $parent ? ($ids[$parent] ?? null) : null,
                        'is_active' => true,
                    ]
                );
                $ids[$code] = $account->id;
            }

            foreach (AccountResolver::CATALOG as $slug => $label) {
                AccountMapping::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'label_ar' => $label,
                        'is_required' => true,
                        'account_id' => DB::table('account_mappings')->where('slug', $slug)->value('account_id')
                            ?? ($ids[self::MAPPING[$slug] ?? ''] ?? null),
                    ]
                );
            }

            foreach (self::UNITS as [$code, $name, $einvoiceCode]) {
                Unit::query()->firstOrCreate(['code' => $code], ['name' => $name, 'einvoice_code' => $einvoiceCode]);
            }

            if (Warehouse::query()->count() === 0) {
                Warehouse::query()->create(['code' => 'MAIN', 'name' => 'مخزن المحل', 'is_active' => true]);
            }

            $this->financialAccount('الصندوق', 'cash', $ids['1101']);
            $this->financialAccount('الحساب البنكي', 'bank', $ids['1102']);
            $this->financialAccount('الشبكة تحت التسوية', 'card_clearing', $ids['1103']);
        });
    }

    private function financialAccount(string $name, string $kind, int $ledgerAccountId): void
    {
        FinancialAccount::query()->firstOrCreate(
            ['ledger_account_id' => $ledgerAccountId],
            ['name' => $name, 'kind' => $kind, 'is_active' => true]
        );
    }
}
