import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import { Alert, Badge, Button, Card, Field, Select, TextInput } from '@/components/ui';
import TaxCodesEditor from '@/components/setup/TaxCodesEditor';
import type { PageProps } from '@/types';

interface SetupState {
    has_user: boolean;
    business: Record<string, unknown> | null;
    chart_installed: boolean;
    units_count: number;
    tax_codes: { id: number; code: string; name: string; category: string; rate: string; is_active: boolean; valid_from: string }[];
    warehouses: { id: number; code: string; name: string }[];
    financial_accounts: { id: number; name: string; kind: string; ledger_account: { code: string; name: string } }[];
    periods: { id: number; name: string; starts_on: string; ends_on: string; status: string }[];
    mappings: { slug: string; label_ar: string; account_id: number | null; is_required: boolean }[];
    postable_accounts: { id: number; code: string; name: string }[];
    missing_required: string[];
    setup_completed: boolean;
}

export default function Wizard() {
    const { state, errors, flash } = usePage<PageProps & { state: SetupState }>().props;
    const [openStep, setOpenStep] = useState<number>(state.has_user ? (state.business ? (state.chart_installed ? 4 : 3) : 2) : 1);

    const steps = [
        { n: 1, title: 'المستخدم الأول', done: state.has_user },
        { n: 2, title: 'بيانات المنشأة', done: !!state.business },
        { n: 3, title: 'دليل الحسابات والوحدات', done: state.chart_installed },
        { n: 4, title: 'المعالجات الضريبية', done: state.tax_codes.length > 0 || state.business?.vat_registered === false },
        { n: 5, title: 'ربط حسابات التشغيل', done: state.missing_required.length === 0 && state.chart_installed },
        { n: 6, title: 'الفترة المحاسبية', done: state.periods.length > 0 },
    ];

    return (
        <div className="mx-auto max-w-4xl space-y-4 p-5">
            <Head title="إعداد المنشأة" />

            <header className="space-y-1">
                <h1 className="text-2xl font-bold text-ink-900">إعداد المنشأة</h1>
                <p className="text-sm text-slate-500">
                    أكمل الخطوات بالترتيب. لا يبدأ التشغيل قبل إتمامها، لأن الترحيل المحاسبي يحتاج الحسابات والفترة والمخزن.
                </p>
            </header>

            {errors.message && <Alert>{errors.message}</Alert>}
            {flash?.success && <Alert tone="success">{flash.success}</Alert>}

            <ol className="flex flex-wrap gap-2 text-sm">
                {steps.map((s) => (
                    <li key={s.n}>
                        <button
                            onClick={() => setOpenStep(s.n)}
                            disabled={!state.has_user && s.n !== 1}
                            className={`flex items-center gap-2 rounded-lg border px-3 py-1.5 transition disabled:opacity-40 ${
                                openStep === s.n ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700' : 'border-slate-200 bg-white'
                            }`}
                        >
                            {s.done ? <Icon name="check" className="size-4 text-emerald-600" /> : <span className="num text-xs">{s.n}</span>}
                            {s.title}
                        </button>
                    </li>
                ))}
            </ol>

            {openStep === 1 && <FirstUserStep hasUser={state.has_user} />}
            {openStep === 2 && state.has_user && <BusinessStep business={state.business} />}
            {openStep === 3 && state.has_user && <ChartStep state={state} />}
            {openStep === 4 && state.has_user && (
                <TaxCodesEditor taxCodes={state.tax_codes} vatRegistered={state.business?.vat_registered === true} />
            )}
            {openStep === 5 && state.has_user && <MappingStep state={state} />}
            {openStep === 6 && state.has_user && <PeriodStep state={state} />}

            {state.has_user && <CompleteCard state={state} />}
        </div>
    );
}

function FirstUserStep({ hasUser }: { hasUser: boolean }) {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

    if (hasUser) {
        return (
            <Card title="المستخدم الأول">
                <Alert tone="success">المستخدم الأول منشأ. إضافة مستخدمين آخرين تتم من الإعدادات.</Alert>
            </Card>
        );
    }

    return (
        <Card title="المستخدم الأول (المالك)">
            <form
                className="grid gap-4 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/setup/first-user');
                }}
            >
                <Field label="الاسم" required error={form.errors.name}>
                    <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                </Field>
                <Field label="البريد الإلكتروني" required error={form.errors.email}>
                    <TextInput type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
                <Field label="كلمة المرور" required hint="10 أحرف على الأقل، حروف وأرقام." error={form.errors.password}>
                    <TextInput type="password" dir="ltr" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
                <Field label="تأكيد كلمة المرور" required>
                    <TextInput
                        type="password"
                        dir="ltr"
                        value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    />
                </Field>
                <div className="sm:col-span-2">
                    <Button type="submit" loading={form.processing}>
                        إنشاء المستخدم والدخول
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function BusinessStep({ business }: { business: Record<string, any> | null }) {
    const form = useForm({
        legal_name: (business?.legal_name as string) ?? '',
        trade_name: (business?.trade_name as string) ?? '',
        registration_number: (business?.registration_number as string) ?? '',
        vat_registered: business?.vat_registered ?? false,
        vat_number: (business?.vat_number as string) ?? '',
        einvoice_phase: (business?.einvoice_phase as string) ?? 'none',
        phone: (business?.phone as string) ?? '',
        email: (business?.email as string) ?? '',
        rounding_decimals: business?.rounding_decimals ?? 2,
        rounding_mode: (business?.rounding_mode as string) ?? 'half_up',
        fiscal_year_start_month: business?.fiscal_year_start_month ?? 1,
        receipt_note_ar: (business?.receipt_note_ar as string) ?? '',
        invoice_note_ar: (business?.invoice_note_ar as string) ?? '',
        address: {
            street: business?.address_json?.street ?? '',
            building_number: business?.address_json?.building_number ?? '',
            district: business?.address_json?.district ?? '',
            city: business?.address_json?.city ?? '',
            postal_code: business?.address_json?.postal_code ?? '',
            additional_number: business?.address_json?.additional_number ?? '',
        },
    });

    return (
        <Card title="بيانات المنشأة والطباعة">
            <form
                className="grid gap-4 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/setup/business');
                }}
            >
                <Field label="الاسم القانوني" required error={form.errors.legal_name}>
                    <TextInput value={form.data.legal_name} onChange={(e) => form.setData('legal_name', e.target.value)} />
                </Field>
                <Field label="الاسم التجاري">
                    <TextInput value={form.data.trade_name} onChange={(e) => form.setData('trade_name', e.target.value)} />
                </Field>
                <Field label="السجل التجاري">
                    <TextInput dir="ltr" value={form.data.registration_number} onChange={(e) => form.setData('registration_number', e.target.value)} />
                </Field>
                <Field label="الهاتف">
                    <TextInput dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                </Field>
                <Field label="مسجّلة في ضريبة القيمة المضافة؟" hint="لا تُفترض المنشأة مسجّلة. الضريبة لا تُحسب إن لم تكن مسجّلة.">
                    <Select
                        value={form.data.vat_registered ? '1' : '0'}
                        onChange={(e) => form.setData('vat_registered', e.target.value === '1')}
                    >
                        <option value="0">غير مسجّلة</option>
                        <option value="1">مسجّلة</option>
                    </Select>
                </Field>
                <Field label="الرقم الضريبي" error={form.errors.vat_number} hint="15 رقمًا يبدأ وينتهي بـ 3.">
                    <TextInput
                        dir="ltr"
                        disabled={!form.data.vat_registered}
                        value={form.data.vat_number}
                        onChange={(e) => form.setData('vat_number', e.target.value)}
                    />
                </Field>
                <Field label="مرحلة الفوترة الإلكترونية">
                    <Select value={form.data.einvoice_phase} onChange={(e) => form.setData('einvoice_phase', e.target.value)}>
                        <option value="none">غير مفعّلة</option>
                        <option value="phase1">المرحلة الأولى</option>
                        <option value="phase2">المرحلة الثانية (الربط)</option>
                    </Select>
                </Field>
                <Field label="البريد الإلكتروني للمنشأة">
                    <TextInput type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>

                <fieldset className="grid gap-3 rounded-lg border border-slate-200 p-4 sm:col-span-2 sm:grid-cols-3">
                    <legend className="px-1 text-sm font-semibold text-ink-700">العنوان الوطني</legend>
                    <Field label="الشارع">
                        <TextInput value={form.data.address.street} onChange={(e) => form.setData('address', { ...form.data.address, street: e.target.value })} />
                    </Field>
                    <Field label="رقم المبنى">
                        <TextInput dir="ltr" value={form.data.address.building_number} onChange={(e) => form.setData('address', { ...form.data.address, building_number: e.target.value })} />
                    </Field>
                    <Field label="الحي">
                        <TextInput value={form.data.address.district} onChange={(e) => form.setData('address', { ...form.data.address, district: e.target.value })} />
                    </Field>
                    <Field label="المدينة">
                        <TextInput value={form.data.address.city} onChange={(e) => form.setData('address', { ...form.data.address, city: e.target.value })} />
                    </Field>
                    <Field label="الرمز البريدي">
                        <TextInput dir="ltr" value={form.data.address.postal_code} onChange={(e) => form.setData('address', { ...form.data.address, postal_code: e.target.value })} />
                    </Field>
                    <Field label="الرقم الإضافي">
                        <TextInput dir="ltr" value={form.data.address.additional_number} onChange={(e) => form.setData('address', { ...form.data.address, additional_number: e.target.value })} />
                    </Field>
                </fieldset>

                <Field label="منازل التقريب" hint="سياسة واحدة لكل النظام.">
                    <Select value={String(form.data.rounding_decimals)} onChange={(e) => form.setData('rounding_decimals', Number(e.target.value))}>
                        <option value="2">2</option>
                        <option value="3">3</option>
                    </Select>
                </Field>
                <Field label="طريقة التقريب">
                    <Select value={form.data.rounding_mode} onChange={(e) => form.setData('rounding_mode', e.target.value)}>
                        <option value="half_up">نصف للأعلى (half up)</option>
                        <option value="half_even">نصف للزوجي (half even)</option>
                    </Select>
                </Field>
                <Field label="بداية السنة المالية (الشهر)">
                    <Select value={String(form.data.fiscal_year_start_month)} onChange={(e) => form.setData('fiscal_year_start_month', Number(e.target.value))}>
                        {Array.from({ length: 12 }, (_, i) => (
                            <option key={i + 1} value={i + 1}>
                                {i + 1}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field label="ملاحظة أسفل إيصال الكاشير">
                    <TextInput value={form.data.receipt_note_ar} onChange={(e) => form.setData('receipt_note_ar', e.target.value)} />
                </Field>

                <div className="sm:col-span-2">
                    <Button type="submit" loading={form.processing}>
                        حفظ بيانات المنشأة
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function ChartStep({ state }: { state: SetupState }) {
    const form = useForm({});

    return (
        <Card title="دليل الحسابات والوحدات والحسابات المالية">
            <div className="space-y-4">
                <p className="text-sm text-slate-600">
                    يثبّت دليلًا محاسبيًا كاملًا للمكتبة (مخزون، تكلفة مبيعات، إيراد سلع وخدمات، مدخلات ومخرجات ضريبية، تحكم كاشير، فروق تقريب
                    وجرد وتلف، فرق تكلفة مرتجع شراء، حقوق ملكية)، مع الوحدات الشائعة، ومخزن المحل، وحسابات الصندوق والبنك والشبكة.
                    التشغيل مرة ثانية لا يكرر شيئًا.
                </p>

                <div className="flex flex-wrap gap-3 text-sm">
                    <Badge tone={state.chart_installed ? 'green' : 'slate'}>{state.chart_installed ? 'الدليل مثبّت' : 'الدليل غير مثبّت'}</Badge>
                    <Badge tone={state.units_count > 0 ? 'green' : 'slate'}>الوحدات: {state.units_count}</Badge>
                    <Badge tone={state.warehouses.length > 0 ? 'green' : 'slate'}>المخازن: {state.warehouses.length}</Badge>
                    <Badge tone={state.financial_accounts.length > 0 ? 'green' : 'slate'}>الحسابات المالية: {state.financial_accounts.length}</Badge>
                </div>

                <Button onClick={() => form.post('/setup/chart')} loading={form.processing} icon="plus">
                    {state.chart_installed ? 'إعادة التحقق وتثبيت الناقص' : 'تثبيت الدليل الافتراضي'}
                </Button>
            </div>
        </Card>
    );
}

function MappingStep({ state }: { state: SetupState }) {
    const form = useForm<{ mappings: Record<string, number | null> }>({
        mappings: Object.fromEntries(state.mappings.map((m) => [m.slug, m.account_id])),
    });

    return (
        <Card title="ربط حسابات التشغيل بدليل الحسابات">
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/setup/mappings');
                }}
            >
                <p className="text-sm text-slate-600">كل قيد يُرحّل بحساب من هذه القائمة. لا يوجد رقم حساب مكتوب داخل الكود.</p>

                <div className="grid gap-3 sm:grid-cols-2">
                    {state.mappings.map((m) => (
                        <Field key={m.slug} label={m.label_ar} required={m.is_required}>
                            <Select
                                value={form.data.mappings[m.slug] ?? ''}
                                onChange={(e) =>
                                    form.setData('mappings', { ...form.data.mappings, [m.slug]: e.target.value ? Number(e.target.value) : null })
                                }
                            >
                                <option value="">— غير مربوط —</option>
                                {state.postable_accounts.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.code} — {a.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    ))}
                </div>

                <Button type="submit" loading={form.processing}>
                    حفظ الربط
                </Button>
            </form>
        </Card>
    );
}

function PeriodStep({ state }: { state: SetupState }) {
    const year = new Date().getFullYear();
    const form = useForm({ name: `السنة المالية ${year}`, starts_on: `${year}-01-01`, ends_on: `${year}-12-31` });

    return (
        <Card title="الفترات المحاسبية">
            <div className="space-y-4">
                {state.periods.length > 0 && (
                    <ul className="space-y-1 text-sm">
                        {state.periods.map((p) => (
                            <li key={p.id} className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2">
                                <span>{p.name}</span>
                                <span className="num text-xs text-slate-500">
                                    {p.starts_on} → {p.ends_on}
                                </span>
                                <Badge tone={p.status === 'open' ? 'green' : 'slate'}>{p.status === 'open' ? 'مفتوحة' : 'مغلقة'}</Badge>
                            </li>
                        ))}
                    </ul>
                )}

                <form
                    className="grid gap-3 sm:grid-cols-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/setup/periods');
                    }}
                >
                    <Field label="اسم الفترة" required>
                        <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>
                    <Field label="من" required>
                        <TextInput type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} />
                    </Field>
                    <Field label="إلى" required>
                        <TextInput type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />
                    </Field>
                    <div className="flex items-end">
                        <Button type="submit" loading={form.processing} className="w-full">
                            إضافة فترة
                        </Button>
                    </div>
                </form>
            </div>
        </Card>
    );
}

function CompleteCard({ state }: { state: SetupState }) {
    const form = useForm({});

    return (
        <Card title="إتمام الإعداد">
            <div className="space-y-3">
                {state.missing_required.length > 0 && (
                    <Alert tone="warning">
                        حسابات تشغيل ما زالت غير مربوطة: {state.missing_required.length}. اربطها من خطوة «ربط حسابات التشغيل».
                    </Alert>
                )}
                <p className="text-sm text-slate-600">
                    بعد الإتمام يفتح النظام بالكامل: الأصناف والأرصدة الافتتاحية والشراء والبيع والتقارير.
                </p>
                <Button onClick={() => form.post('/setup/complete')} loading={form.processing} icon="check" size="lg">
                    {state.setup_completed ? 'الإعداد مكتمل — الدخول للنظام' : 'إتمام الإعداد والدخول'}
                </Button>
            </div>
        </Card>
    );
}
