import { useForm } from '@inertiajs/react';
import BarcodeScannerButton from '@/components/BarcodeScannerButton';
import { Alert, Button, Card, Field, Select, TextInput } from '@/components/ui';

export interface ItemUnitRecord {
    id?: number;
    unit_id: number;
    to_base_factor: string;
    sale_price: string;
    price_includes_tax: boolean;
    is_active?: boolean;
    unit?: { id: number; code: string; name: string };
    barcodes?: { id: number; barcode: string }[];
}

export interface ItemRecord {
    id: number;
    sku: string;
    name_ar: string;
    name_en: string | null;
    item_type: 'stock' | 'service';
    category_id: number | null;
    base_unit_id: number;
    tax_code_id: number | null;
    reorder_level: string;
    revenue_account_id: number | null;
    inventory_account_id: number | null;
    cost_account_id: number | null;
    is_active: boolean;
    units?: ItemUnitRecord[];
    tax_code?: { id: number; name: string } | null;
}

export interface CatalogOptions {
    units: { id: number; code: string; name: string }[];
    categories: { id: number; name: string }[];
    tax_codes: { id: number; code: string; name: string; category: string; rate: string }[];
    accounts: { id: number; code: string; name: string; account_type: string }[];
    materials: { id: number; sku: string; name_ar: string }[];
}

interface UnitRow {
    unit_id: number;
    to_base_factor: string;
    sale_price: string;
    price_includes_tax: boolean;
    is_active: boolean;
    barcodes: string;
}

export default function ItemForm({ item, options, onClose }: { item: ItemRecord | null; options: CatalogOptions; onClose: () => void }) {
    const defaultUnitId = options.units[0]?.id ?? 0;

    const form = useForm({
        sku: item?.sku ?? '',
        name_ar: item?.name_ar ?? '',
        name_en: item?.name_en ?? '',
        item_type: item?.item_type ?? ('stock' as 'stock' | 'service'),
        category_id: item?.category_id ?? '',
        base_unit_id: item?.base_unit_id ?? defaultUnitId,
        tax_code_id: item?.tax_code_id ?? '',
        reorder_level: item?.reorder_level ?? '0',
        revenue_account_id: item?.revenue_account_id ?? '',
        inventory_account_id: item?.inventory_account_id ?? '',
        cost_account_id: item?.cost_account_id ?? '',
        is_active: item?.is_active ?? true,
        units: (item?.units?.map((u) => ({
            unit_id: u.unit_id,
            to_base_factor: u.to_base_factor,
            sale_price: u.sale_price,
            price_includes_tax: u.price_includes_tax,
            is_active: u.is_active ?? true,
            barcodes: (u.barcodes ?? []).map((b) => b.barcode).join(', '),
        })) ?? [{ unit_id: defaultUnitId, to_base_factor: '1', sale_price: '0', price_includes_tax: true, is_active: true, barcodes: '' }]) as UnitRow[],
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const payload = {
            ...form.data,
            units: form.data.units.map((u) => ({
                ...u,
                to_base_factor: Number(u.unit_id) === Number(form.data.base_unit_id) ? '1' : u.to_base_factor,
                barcodes: u.barcodes.split(',').map((b) => b.trim()).filter(Boolean),
            })),
        };

        const options_ = { onSuccess: () => onClose(), preserveScroll: true };

        // transform() في نسخة React لا تُرجع الفورم، فالاستدعاء يتم على سطرين لا بالتسلسل
        form.transform(() => payload);

        if (item) {
            form.patch(`/items/${item.id}`, options_);
        } else {
            form.post('/items', options_);
        }
    };

    const setUnit = (index: number, patch: Partial<UnitRow>) => {
        const units = [...form.data.units];
        units[index] = { ...units[index], ...patch };
        form.setData('units', units);
    };

    return (
        <Card title={item ? `تعديل: ${item.name_ar}` : 'صنف أو خدمة جديدة'} action={<Button variant="ghost" onClick={onClose}>إغلاق</Button>}>
            <form className="space-y-5" onSubmit={submit}>
                {form.errors.message && <Alert>{form.errors.message}</Alert>}

                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="رمز الصنف SKU" required error={form.errors.sku}>
                        <TextInput dir="ltr" value={form.data.sku} onChange={(e) => form.setData('sku', e.target.value)} />
                    </Field>
                    <Field label="الاسم العربي" required error={form.errors.name_ar}>
                        <TextInput value={form.data.name_ar} onChange={(e) => form.setData('name_ar', e.target.value)} />
                    </Field>
                    <Field label="الاسم الإنجليزي">
                        <TextInput dir="ltr" value={form.data.name_en ?? ''} onChange={(e) => form.setData('name_en', e.target.value)} />
                    </Field>

                    <Field label="النوع" required hint="الخدمة لا تملك رصيد مخزون.">
                        <Select value={form.data.item_type} onChange={(e) => form.setData('item_type', e.target.value as 'stock' | 'service')}>
                            <option value="stock">سلعة مخزنية</option>
                            <option value="service">خدمة</option>
                        </Select>
                    </Field>
                    <Field label="التصنيف">
                        <Select value={String(form.data.category_id ?? '')} onChange={(e) => form.setData('category_id', e.target.value)}>
                            <option value="">بدون</option>
                            {options.categories.map((c) => (
                                <option key={c.id} value={c.id}>{c.name}</option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="المعالجة الضريبية" hint="لكل صنف معالجته؛ لا نسبة موحدة مفروضة.">
                        <Select value={String(form.data.tax_code_id ?? '')} onChange={(e) => form.setData('tax_code_id', e.target.value)}>
                            <option value="">بدون ضريبة</option>
                            {options.tax_codes.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name} ({Number(t.rate).toFixed(2)}%)
                                </option>
                            ))}
                        </Select>
                    </Field>

                    <Field label="الوحدة الأساسية" required hint="كل الأرصدة تُحفظ بها، ومعاملها 1.">
                        <Select value={String(form.data.base_unit_id)} onChange={(e) => form.setData('base_unit_id', Number(e.target.value))}>
                            {options.units.map((u) => (
                                <option key={u.id} value={u.id}>{u.name} ({u.code})</option>
                            ))}
                        </Select>
                    </Field>
                    {form.data.item_type === 'stock' && (
                        <Field label="حد إعادة الطلب">
                            <TextInput dir="ltr" className="num" value={form.data.reorder_level} onChange={(e) => form.setData('reorder_level', e.target.value)} />
                        </Field>
                    )}
                    <Field label="الحالة">
                        <Select value={form.data.is_active ? '1' : '0'} onChange={(e) => form.setData('is_active', e.target.value === '1')}>
                            <option value="1">نشط</option>
                            <option value="0">موقوف</option>
                        </Select>
                    </Field>
                </div>

                <fieldset className="space-y-3 rounded-lg border border-slate-200 p-4">
                    <legend className="px-1 text-sm font-semibold text-ink-700">الوحدات والأسعار والباركود</legend>

                    {form.data.units.map((row, index) => {
                        const isBase = Number(row.unit_id) === Number(form.data.base_unit_id);

                        return (
                            <div key={index} className="grid items-end gap-3 sm:grid-cols-6">
                                <Field label="الوحدة">
                                    <Select value={String(row.unit_id)} onChange={(e) => setUnit(index, { unit_id: Number(e.target.value) })}>
                                        {options.units.map((u) => (
                                            <option key={u.id} value={u.id}>{u.name}</option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="يساوي كم وحدة أساسية" hint={isBase ? 'الوحدة الأساسية = 1' : 'مثال: علبة = 12'}>
                                    <TextInput dir="ltr" className="num" disabled={isBase} value={isBase ? '1' : row.to_base_factor} onChange={(e) => setUnit(index, { to_base_factor: e.target.value })} />
                                </Field>
                                <Field label="سعر البيع">
                                    <TextInput dir="ltr" className="num" value={row.sale_price} onChange={(e) => setUnit(index, { sale_price: e.target.value })} />
                                </Field>
                                <Field label="السعر شامل الضريبة؟">
                                    <Select value={row.price_includes_tax ? '1' : '0'} onChange={(e) => setUnit(index, { price_includes_tax: e.target.value === '1' })}>
                                        <option value="1">شامل</option>
                                        <option value="0">غير شامل</option>
                                    </Select>
                                </Field>
                                <Field label="الباركود" hint="افصل بفاصلة عند تعدد الباركود.">
                                    <div className="flex gap-2">
                                        <TextInput dir="ltr" className="num" value={row.barcodes} onChange={(e) => setUnit(index, { barcodes: e.target.value })} />
                                        <BarcodeScannerButton
                                            label=""
                                            onScan={(code) => setUnit(index, { barcodes: row.barcodes ? `${row.barcodes}, ${code}` : code })}
                                        />
                                    </div>
                                </Field>
                                <div>
                                    {form.data.units.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            onClick={() => form.setData('units', form.data.units.filter((_, i) => i !== index))}
                                        >
                                            حذف
                                        </Button>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        icon="plus"
                        onClick={() =>
                            form.setData('units', [
                                ...form.data.units,
                                { unit_id: options.units[0]?.id ?? 0, to_base_factor: '1', sale_price: '0', price_includes_tax: true, is_active: true, barcodes: '' },
                            ])
                        }
                    >
                        إضافة وحدة
                    </Button>
                </fieldset>

                <fieldset className="grid gap-4 rounded-lg border border-slate-200 p-4 sm:grid-cols-3">
                    <legend className="px-1 text-sm font-semibold text-ink-700">الحسابات المرتبطة (اختيارية — تُستخدم بدل الحساب الافتراضي)</legend>
                    <Field label="حساب الإيراد">
                        <AccountSelect value={form.data.revenue_account_id} onChange={(v) => form.setData('revenue_account_id', v)} accounts={options.accounts} />
                    </Field>
                    {form.data.item_type === 'stock' && (
                        <Field label="حساب المخزون">
                            <AccountSelect value={form.data.inventory_account_id} onChange={(v) => form.setData('inventory_account_id', v)} accounts={options.accounts} />
                        </Field>
                    )}
                    <Field label="حساب التكلفة">
                        <AccountSelect value={form.data.cost_account_id} onChange={(v) => form.setData('cost_account_id', v)} accounts={options.accounts} />
                    </Field>
                </fieldset>

                <div className="flex gap-2">
                    <Button type="submit" loading={form.processing}>
                        {item ? 'حفظ التعديلات' : 'إضافة الصنف'}
                    </Button>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        إلغاء
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function AccountSelect({
    value,
    onChange,
    accounts,
}: {
    value: number | string | null;
    onChange: (v: string) => void;
    accounts: CatalogOptions['accounts'];
}) {
    return (
        <Select value={String(value ?? '')} onChange={(e) => onChange(e.target.value)}>
            <option value="">الحساب الافتراضي من ربط الحسابات</option>
            {accounts.map((a) => (
                <option key={a.id} value={a.id}>
                    {a.code} — {a.name}
                </option>
            ))}
        </Select>
    );
}
