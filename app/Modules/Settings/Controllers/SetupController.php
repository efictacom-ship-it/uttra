<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\PeriodService;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Settings\Models\BusinessSetting;
use App\Modules\Settings\Models\TaxCode;
use App\Modules\Settings\Services\DefaultChartInstaller;
use App\Shared\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * شاشة الإعداد الأولي. المستخدم الأول يُنشأ من هنا مرة واحدة فقط
 * (لا كلمة مرور ثابتة في أي seeder).
 */
class SetupController
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AccountResolver $accounts,
        private readonly PeriodService $periods,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $hasUser = User::query()->exists();

        if ($hasUser && ! $request->user()) {
            return redirect('/login');
        }

        $settings = BusinessSetting::query()->find(1);

        return Inertia::render('Setup/Wizard', [
            'state' => [
                'has_user' => $hasUser,
                'business' => $settings,
                'chart_installed' => Account::query()->exists(),
                'units_count' => Unit::query()->count(),
                'tax_codes' => TaxCode::query()->orderBy('code')->get(),
                'warehouses' => Warehouse::query()->get(['id', 'code', 'name']),
                'financial_accounts' => FinancialAccount::query()->with('ledgerAccount:id,code,name')->get(),
                'periods' => AccountingPeriod::query()->orderBy('starts_on')->get(),
                'mappings' => AccountMapping::query()->orderBy('id')->get(),
                'postable_accounts' => Account::query()->where('allow_posting', true)->where('is_active', true)
                    ->orderBy('code')->get(['id', 'code', 'name', 'account_type']),
                'missing_required' => $this->accounts->missingRequired(),
                'setup_completed' => $settings?->setup_completed_at !== null,
            ],
        ]);
    }

    public function createFirstUser(Request $request): RedirectResponse
    {
        if (User::query()->exists()) {
            throw ValidationException::withMessages(['email' => 'المستخدم الأول موجود بالفعل. استخدم شاشة الدخول.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'role' => 'owner', 'is_active' => true]);
        $user->setPassword($data['password']);
        $user->save();

        Auth::login($user);
        $request->session()->regenerate();
        $this->audit->log('user', $user->id, 'first_user_created');

        return back()->with('success', 'تم إنشاء المستخدم الأول وتسجيل الدخول.');
    }

    public function saveBusiness(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'vat_registered' => ['required', 'boolean'],
            'vat_number' => ['nullable', 'required_if:vat_registered,true', 'string', 'regex:/^3\d{13}3$/'],
            'einvoice_phase' => ['required', 'in:none,phase1,phase2'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'rounding_decimals' => ['required', 'integer', 'between:0,6'],
            'rounding_mode' => ['required', 'in:half_up,half_even'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'receipt_note_ar' => ['nullable', 'string', 'max:500'],
            'invoice_note_ar' => ['nullable', 'string', 'max:500'],
            'address.street' => ['nullable', 'string', 'max:120'],
            'address.building_number' => ['nullable', 'string', 'max:10'],
            'address.district' => ['nullable', 'string', 'max:120'],
            'address.city' => ['nullable', 'string', 'max:120'],
            'address.postal_code' => ['nullable', 'string', 'max:10'],
            'address.additional_number' => ['nullable', 'string', 'max:10'],
        ], [
            'vat_number.regex' => 'الرقم الضريبي يجب أن يكون 15 رقمًا يبدأ وينتهي بالرقم 3.',
            'vat_number.required_if' => 'الرقم الضريبي مطلوب عند تسجيل المنشأة في ضريبة القيمة المضافة.',
        ]);

        $before = BusinessSetting::query()->find(1)?->toArray();

        $settings = BusinessSetting::persist([
            'legal_name' => $data['legal_name'],
            'trade_name' => $data['trade_name'] ?? null,
            'registration_number' => $data['registration_number'] ?? null,
            'vat_registered' => $data['vat_registered'],
            'vat_number' => $data['vat_registered'] ? $data['vat_number'] : null,
            'einvoice_phase' => $data['einvoice_phase'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'rounding_decimals' => $data['rounding_decimals'],
            'rounding_mode' => $data['rounding_mode'],
            'fiscal_year_start_month' => $data['fiscal_year_start_month'],
            'receipt_note_ar' => $data['receipt_note_ar'] ?? null,
            'invoice_note_ar' => $data['invoice_note_ar'] ?? null,
            'address_json' => $request->input('address'),
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
        ]);

        $this->audit->log('business_settings', 1, 'updated', $before, $settings->toArray());

        return back()->with('success', 'تم حفظ بيانات المنشأة.');
    }

    public function installChart(DefaultChartInstaller $installer): RedirectResponse
    {
        $installer->install();
        $this->accounts->forget();
        $this->audit->log('accounts', null, 'default_chart_installed');

        return back()->with('success', 'تم تثبيت دليل الحسابات والوحدات والحسابات المالية.');
    }

    public function saveMappings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        foreach ($data['mappings'] as $slug => $accountId) {
            AccountMapping::query()->where('slug', $slug)->update(['account_id' => $accountId]);
        }

        $this->accounts->forget();
        $this->audit->log('account_mappings', null, 'updated', null, $data['mappings']);

        return back()->with('success', 'تم تحديث ربط حسابات التشغيل.');
    }

    public function savePeriod(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);

        $this->periods->assertNoOverlap($data['starts_on'], $data['ends_on']);

        AccountingPeriod::query()->create($data + ['status' => 'open']);
        $this->audit->log('accounting_period', null, 'created', null, $data);

        return back()->with('success', 'تم إنشاء الفترة المحاسبية.');
    }

    public function complete(Request $request): RedirectResponse
    {
        $settings = BusinessSetting::query()->find(1);

        $problems = [];
        if (! $settings) {
            $problems[] = 'بيانات المنشأة غير محفوظة.';
        }
        if (! Account::query()->exists()) {
            $problems[] = 'دليل الحسابات غير مثبّت.';
        }
        if ($missing = $this->accounts->missingRequired()) {
            $problems[] = 'حسابات تشغيل غير مربوطة: '.implode('، ', array_map(fn ($s) => AccountResolver::CATALOG[$s] ?? $s, $missing));
        }
        if (! Warehouse::query()->exists()) {
            $problems[] = 'لا يوجد مخزن.';
        }
        if (! AccountingPeriod::query()->exists()) {
            $problems[] = 'لا توجد فترة محاسبية.';
        }
        if ($settings?->vat_registered && ! TaxCode::query()->where('is_active', true)->exists()) {
            $problems[] = 'المنشأة مسجّلة ضريبيًا ولا توجد معالجات ضريبية معرّفة.';
        }

        if ($problems) {
            throw ValidationException::withMessages(['message' => 'لا يمكن إكمال الإعداد: '.implode(' ', $problems)]);
        }

        $settings->update(['setup_completed_at' => now()]);
        $this->audit->log('business_settings', 1, 'setup_completed');

        return redirect('/dashboard')->with('success', 'تم إكمال الإعداد. النظام جاهز للعمل.');
    }
}
