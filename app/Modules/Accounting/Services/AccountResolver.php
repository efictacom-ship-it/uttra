<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AccountMapping;
use DomainException;

/**
 * كل حساب يحتاجه منطق الترحيل يُطلب من هنا بالـ slug.
 * لا يوجد رقم حساب ثابت داخل أي خدمة.
 */
final class AccountResolver
{
    public const SALES_REVENUE = 'sales_revenue';
    public const SERVICE_REVENUE = 'service_revenue';
    public const VAT_OUTPUT = 'vat_output';
    public const VAT_INPUT_RECOVERABLE = 'vat_input_recoverable';
    public const INVENTORY = 'inventory';
    public const COST_OF_GOODS_SOLD = 'cost_of_goods_sold';
    public const POS_SALES_CONTROL = 'pos_sales_control';
    public const ROUNDING_DIFFERENCE = 'rounding_difference';
    public const INVENTORY_SHRINKAGE = 'inventory_shrinkage';
    public const STOCK_COUNT_DIFFERENCE = 'stock_count_difference';
    public const PURCHASE_RETURN_COST_DIFFERENCE = 'purchase_return_cost_difference';
    public const OWNER_EQUITY = 'owner_equity';
    public const OPENING_BALANCE = 'opening_balance';
    public const CARD_SETTLEMENT_FEE = 'card_settlement_fee';
    public const CUSTOMER_ADVANCES = 'customer_advances';
    public const SUPPLIER_ADVANCES = 'supplier_advances';
    public const ACCOUNTS_RECEIVABLE = 'accounts_receivable';
    public const ACCOUNTS_PAYABLE = 'accounts_payable';
    public const MATERIAL_CONSUMPTION = 'material_consumption';

    /** @var array<string, string> القائمة الكاملة مع تسمياتها العربية، تُزرع في جدول الربط. */
    public const CATALOG = [
        self::SALES_REVENUE => 'إيراد بيع السلع',
        self::SERVICE_REVENUE => 'إيراد الخدمات الطلابية',
        self::VAT_OUTPUT => 'ضريبة القيمة المضافة - مخرجات',
        self::VAT_INPUT_RECOVERABLE => 'ضريبة القيمة المضافة - مدخلات قابلة للخصم',
        self::INVENTORY => 'المخزون',
        self::COST_OF_GOODS_SOLD => 'تكلفة البضاعة المباعة',
        self::MATERIAL_CONSUMPTION => 'تكلفة خامات الخدمات',
        self::POS_SALES_CONTROL => 'حساب تحكم مبيعات الكاشير',
        self::ACCOUNTS_RECEIVABLE => 'العملاء (مدينون)',
        self::ACCOUNTS_PAYABLE => 'الموردون (دائنون)',
        self::CUSTOMER_ADVANCES => 'دفعات عملاء مقدمة',
        self::SUPPLIER_ADVANCES => 'دفعات موردين مقدمة',
        self::ROUNDING_DIFFERENCE => 'فروق التقريب',
        self::INVENTORY_SHRINKAGE => 'التلف والفقد',
        self::STOCK_COUNT_DIFFERENCE => 'فروقات الجرد',
        self::PURCHASE_RETURN_COST_DIFFERENCE => 'فرق تكلفة مرتجع الشراء',
        self::CARD_SETTLEMENT_FEE => 'رسوم تسوية الشبكة',
        self::OWNER_EQUITY => 'حقوق الملكية',
        self::OPENING_BALANCE => 'حساب الأرصدة الافتتاحية',
    ];

    /** @var array<string, int>|null */
    private ?array $cache = null;

    public function id(string $slug): int
    {
        $map = $this->all();

        if (! array_key_exists($slug, $map) || $map[$slug] === null) {
            throw new DomainException("الحساب المحاسبي «{$slug}» غير مربوط. اربطه من شاشة ربط الحسابات قبل الترحيل.");
        }

        return $map[$slug];
    }

    public function idOrNull(string $slug): ?int
    {
        return $this->all()[$slug] ?? null;
    }

    /** @return array<string, int|null> */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = AccountMapping::query()->pluck('account_id', 'slug')->all();
        }

        return $this->cache;
    }

    /** @return list<string> الأسماء المطلوبة وغير المربوطة. */
    public function missingRequired(): array
    {
        return AccountMapping::query()
            ->where('is_required', true)
            ->whereNull('account_id')
            ->pluck('slug')
            ->all();
    }

    public function forget(): void
    {
        $this->cache = null;
    }
}
