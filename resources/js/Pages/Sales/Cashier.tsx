import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import BarcodeScannerButton from '@/components/BarcodeScannerButton';
import { Alert, Badge, Button, Card, Field, Money, NumberInput, Select, Table, TextInput } from '@/components/ui';
import { dateAr, methodLabels, money } from '@/lib/format';
import type { PageProps } from '@/types';

interface ItemUnitOption {
    id: number;
    unit_id: number;
    to_base_factor: string;
    sale_price: string;
    price_includes_tax: boolean;
    unit?: { name: string };
    barcodes?: { barcode: string }[];
}

interface ItemOption {
    id: number;
    sku: string;
    name_ar: string;
    item_type: 'stock' | 'service' | 'expense';
    units: ItemUnitOption[];
}

interface CartLine {
    key: string;
    item_id: number;
    item_unit_id: number;
    description: string;
    quantity: string;
    unit_price: string;
    discount_amount: string;
    price_includes_tax: boolean;
    item_name: string;
    unit_name: string;
}

interface DraftDoc {
    id: number;
    business_date: string;
    grand_total: string;
    party_id: number | null;
    warehouse_id: number | null;
    document_discount?: string;
    lines: {
        id: number;
        item_id: number | null;
        item_unit_id: number | null;
        description: string;
        quantity: string;
        unit_price: string;
        discount_amount: string;
        price_includes_tax_snapshot: boolean;
        unit_name_snapshot: string;
    }[];
}

interface CashierProps extends PageProps {
    options: {
        customers: { id: number; name: string }[];
        warehouses: { id: number; name: string }[];
        financial_accounts: { id: number; name: string; kind: string }[];
        items: ItemOption[];
    };
    draft: DraftDoc | null;
    heldDrafts: { id: number; business_date: string; grand_total: string; party_id: number | null; created_at: string }[];
}

function newKey() {
    return Math.random().toString(36).slice(2);
}

function lineFromItemUnit(item: ItemOption, unit: ItemUnitOption): CartLine {
    return {
        key: newKey(),
        item_id: item.id,
        item_unit_id: unit.id,
        description: item.name_ar,
        quantity: '1',
        unit_price: unit.sale_price,
        discount_amount: '0',
        price_includes_tax: unit.price_includes_tax,
        item_name: item.name_ar,
        unit_name: unit.unit?.name ?? '',
    };
}

export default function Cashier() {
    const { options, draft, heldDrafts, errors } = usePage<CashierProps>().props;
    const [partyId, setPartyId] = useState<number | ''>(draft?.party_id ?? '');
    const [warehouseId, setWarehouseId] = useState<number | ''>(draft?.warehouse_id ?? options.warehouses[0]?.id ?? '');
    const [businessDate, setBusinessDate] = useState(draft?.business_date ?? new Date().toISOString().slice(0, 10));
    const [documentDiscount, setDocumentDiscount] = useState(draft?.document_discount ?? '0');
    const [lines, setLines] = useState<CartLine[]>(
        draft
            ? draft.lines.map((l) => ({
                  key: newKey(),
                  item_id: l.item_id ?? 0,
                  item_unit_id: l.item_unit_id ?? 0,
                  description: l.description,
                  quantity: l.quantity,
                  unit_price: l.unit_price,
                  discount_amount: l.discount_amount,
                  price_includes_tax: l.price_includes_tax_snapshot,
                  item_name: l.description,
                  unit_name: l.unit_name_snapshot,
              }))
            : [],
    );
    const [query, setQuery] = useState('');
    const [savedDraftId, setSavedDraftId] = useState<number | null>(draft?.id ?? null);
    const [savedGrandTotal, setSavedGrandTotal] = useState<string | null>(draft?.grand_total ?? null);
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const [paying, setPaying] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('checkout') === '1');

    const focusSearch = () => document.getElementById('cashier-search')?.focus();

    useEffect(() => {
        focusSearch();
    }, []);

    const services = useMemo(() => options.items.filter((i) => i.item_type === 'service').slice(0, 12), [options.items]);

    const matches = useMemo(() => {
        if (!query.trim()) return [];
        const q = query.trim().toLowerCase();

        // مطابقة باركود دقيقة أولًا (قارئ الباركود = إدخال لوحة مفاتيح سريع)
        for (const item of options.items) {
            for (const unit of item.units) {
                if (unit.barcodes?.some((b) => b.barcode === query.trim())) {
                    return [{ item, unit, exact: true }];
                }
            }
        }

        const found: { item: ItemOption; unit: ItemUnitOption; exact: boolean }[] = [];
        for (const item of options.items) {
            if (item.name_ar.toLowerCase().includes(q) || item.sku.toLowerCase().includes(q)) {
                const unit = item.units[0];
                if (unit) found.push({ item, unit, exact: false });
            }
            if (found.length >= 8) break;
        }
        return found;
    }, [query, options.items]);

    const addLine = (item: ItemOption, unit: ItemUnitOption) => {
        setLines((prev) => [...prev, lineFromItemUnit(item, unit)]);
        setQuery('');
        focusSearch();
    };

    const onSearchKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' && matches.length > 0) {
            e.preventDefault();
            addLine(matches[0].item, matches[0].unit);
        }
    };

    const [scanNotFound, setScanNotFound] = useState(false);

    /** يبحث عن مطابقة باركود دقيقة ويضيف الصنف مباشرة، بلا حاجة لمفتاح Enter. */
    const onCameraScan = (code: string) => {
        for (const item of options.items) {
            for (const unit of item.units) {
                if (unit.barcodes?.some((b) => b.barcode === code)) {
                    setScanNotFound(false);
                    addLine(item, unit);
                    return;
                }
            }
        }
        setScanNotFound(true);
        setQuery(code);
        focusSearch();
    };

    const patchLine = (key: string, changes: Partial<CartLine>) => {
        setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...changes } : l)));
    };

    const removeLine = (key: string) => setLines((prev) => prev.filter((l) => l.key !== key));

    const estimateTotal = lines.reduce(
        (sum, l) => sum + Number(l.quantity || 0) * Number(l.unit_price || 0) - Number(l.discount_amount || 0),
        0,
    ) - Number(documentDiscount || 0);

    const payload = (checkout: boolean) => ({
        party_id: partyId || null,
        warehouse_id: warehouseId,
        business_date: businessDate,
        document_discount: documentDiscount,
        idempotency_key: idempotencyKey,
        checkout,
        lines: lines.map((l) => ({
            item_id: l.item_id,
            item_unit_id: l.item_unit_id,
            description: l.description,
            quantity: l.quantity,
            unit_price: l.unit_price,
            discount_amount: l.discount_amount,
            price_includes_tax: l.price_includes_tax,
        })),
    });

    const [saving, setSaving] = useState(false);

    /** حفظ السلة: تنتقل الصفحة إلى /pos?draft=ID (وربما &checkout=1) بعد نجاح الحفظ على السيرفر. */
    const saveDraft = (checkout = false) => {
        if (lines.length === 0) return;

        setSaving(true);
        const onFinish = () => setSaving(false);

        if (savedDraftId) {
            router.patch(`/sales/${savedDraftId}`, payload(checkout), { onFinish });
            return;
        }

        router.post('/sales', payload(checkout), { onFinish });
    };

    // عند عودة الصفحة بعد الحفظ بمسودة (?draft=ID) تُحدَّث الحالة من نفس البيانات المرحّلة على السيرفر
    useEffect(() => {
        if (draft) {
            setSavedDraftId(draft.id);
            setSavedGrandTotal(draft.grand_total);
        }
    }, [draft]);

    /** سلة محفوظة فعلًا (draft.id يطابق ما لدينا) تفتح شاشة الدفع مباشرة؛ غير ذلك تُحفظ أولًا ثم تعود بعلامة checkout. */
    const startCheckout = () => {
        if (savedDraftId && draft?.id === savedDraftId) {
            setPaying(true);
            return;
        }
        saveDraft(true);
    };

    const newCart = () => router.visit('/pos');

    return (
        <AppLayout
            title="الكاشير"
            actions={
                <div className="flex gap-2">
                    <Button variant="secondary" icon="plus" onClick={newCart}>
                        سلة جديدة
                    </Button>
                    <Link href="/sales" className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-ink-900 hover:bg-slate-50">
                        فواتير البيع
                    </Link>
                </div>
            }
        >
            {errors.message && <Alert>{errors.message}</Alert>}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <div className="grid gap-3 sm:grid-cols-4">
                            <Field label="بحث بالاسم / SKU / الباركود">
                                <div className="flex gap-2">
                                    <TextInput
                                        id="cashier-search"
                                        value={query}
                                        onChange={(e) => {
                                            setScanNotFound(false);
                                            setQuery(e.target.value);
                                        }}
                                        onKeyDown={onSearchKeyDown}
                                        placeholder="امسح الباركود أو اكتب اسم الصنف..."
                                        autoFocus
                                    />
                                    <BarcodeScannerButton label="" onScan={onCameraScan} />
                                </div>
                                {scanNotFound && <span className="mt-1 block text-xs font-medium text-rose-600">لا يوجد صنف بهذا الباركود.</span>}
                            </Field>
                            <Field label="العميل (اختياري، إلزامي للبيع الآجل)">
                                <Select value={String(partyId)} onChange={(e) => setPartyId(e.target.value ? Number(e.target.value) : '')}>
                                    <option value="">بيع نقدي بلا عميل</option>
                                    {options.customers.map((c) => (
                                        <option key={c.id} value={c.id}>{c.name}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="المخزن">
                                <Select value={String(warehouseId)} onChange={(e) => setWarehouseId(Number(e.target.value))}>
                                    {options.warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>{w.name}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="التاريخ">
                                <TextInput type="date" value={businessDate} onChange={(e) => setBusinessDate(e.target.value)} />
                            </Field>
                        </div>

                        {matches.length > 0 && (
                            <ul className="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
                                {matches.map(({ item, unit }) => (
                                    <li key={unit.id}>
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between px-3 py-2 text-start text-sm hover:bg-slate-50"
                                            onClick={() => addLine(item, unit)}
                                        >
                                            <span>{item.name_ar} <span className="num text-xs text-slate-500">({item.sku})</span></span>
                                            <span className="num text-brand-700">{money(unit.sale_price)} / {unit.unit?.name}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {services.length > 0 && (
                            <div className="mt-3">
                                <p className="mb-1.5 text-xs font-semibold text-slate-500">خدمات سريعة</p>
                                <div className="flex flex-wrap gap-2">
                                    {services.map((item) => {
                                        const unit = item.units[0];
                                        if (!unit) return null;
                                        return (
                                            <Button key={item.id} type="button" variant="secondary" size="sm" onClick={() => addLine(item, unit)}>
                                                {item.name_ar}
                                            </Button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </Card>

                    <Card title={`السلة (${lines.length} سطر)`}>
                        <Table
                            head={['الصنف', 'الوحدة', 'الكمية', 'السعر', 'الخصم', 'الإجمالي التقديري', '']}
                            empty={lines.length === 0}
                        >
                            {lines.map((line) => {
                                const lineTotal = Number(line.quantity || 0) * Number(line.unit_price || 0) - Number(line.discount_amount || 0);
                                return (
                                    <tr key={line.key}>
                                        <td className="px-3 py-2">{line.item_name}</td>
                                        <td className="px-3 py-2">{line.unit_name}</td>
                                        <td className="px-3 py-2">
                                            <NumberInput
                                                className="w-20"
                                                value={line.quantity}
                                                onChange={(e) => patchLine(line.key, { quantity: e.target.value })}
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <NumberInput
                                                className="w-24"
                                                value={line.unit_price}
                                                onChange={(e) => patchLine(line.key, { unit_price: e.target.value })}
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <NumberInput
                                                className="w-20"
                                                value={line.discount_amount}
                                                onChange={(e) => patchLine(line.key, { discount_amount: e.target.value })}
                                            />
                                        </td>
                                        <td className="px-3 py-2 font-semibold"><Money value={lineTotal} /></td>
                                        <td className="px-3 py-2">
                                            <Button type="button" variant="ghost" size="sm" icon="trash" onClick={() => removeLine(line.key)}>
                                                <span className="sr-only">حذف</span>
                                            </Button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </Table>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card title="الإجمالي">
                        <Field label="خصم الفاتورة">
                            <NumberInput value={documentDiscount} onChange={(e) => setDocumentDiscount(e.target.value)} />
                        </Field>
                        <div className="mt-3 flex items-center justify-between text-lg font-bold">
                            <span>الإجمالي التقديري</span>
                            <Money value={estimateTotal} />
                        </div>
                        <p className="mt-1 text-xs text-slate-500">تقديري قبل الضريبة — السيرفر يحسب الإجمالي الفعلي شاملًا الضريبة عند الحفظ.</p>

                        {savedDraftId && savedGrandTotal && (
                            <div className="mt-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                                محفوظ برقم داخلي #{savedDraftId} — الإجمالي الفعلي: <Money value={savedGrandTotal} />
                            </div>
                        )}

                        <div className="mt-4 flex flex-col gap-2">
                            <Button loading={saving} disabled={lines.length === 0} onClick={() => saveDraft(false)}>
                                تعليق السلة (حفظ كمسودة)
                            </Button>
                            <Button variant="secondary" disabled={lines.length === 0} onClick={startCheckout}>
                                تحصيل ({lines.length} سطر)
                            </Button>
                        </div>
                    </Card>

                    {heldDrafts.length > 0 && (
                        <Card title="سلال معلّقة">
                            <ul className="space-y-1.5 text-sm">
                                {heldDrafts.map((d) => (
                                    <li key={d.id}>
                                        <Link href={`/pos?draft=${d.id}`} className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 hover:bg-slate-50">
                                            <span>#{d.id} — {dateAr(d.business_date)}</span>
                                            <Money value={d.grand_total} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>

            {paying && savedDraftId && (
                <PaymentModal
                    documentId={savedDraftId}
                    grandTotal={savedGrandTotal ?? '0'}
                    hasCustomer={!!partyId}
                    accounts={options.financial_accounts}
                    onClose={() => setPaying(false)}
                />
            )}
        </AppLayout>
    );
}

interface PaymentRow {
    method: string;
    financial_account_id: number | '';
    amount: string;
}

function PaymentModal({
    documentId,
    grandTotal,
    hasCustomer,
    accounts,
    onClose,
}: {
    documentId: number;
    grandTotal: string;
    hasCustomer: boolean;
    accounts: { id: number; name: string; kind: string }[];
    onClose: () => void;
}) {
    const cashAccount = accounts.find((a) => a.kind === 'cash') ?? accounts[0];
    const form = useForm<{ payments: PaymentRow[] }>({
        payments: [{ method: 'cash', financial_account_id: cashAccount?.id ?? '', amount: grandTotal }],
    });

    const paid = form.data.payments.reduce((s, p) => s + Number(p.amount || 0), 0);
    const remaining = Number(grandTotal) - paid;
    const isCredit = remaining > 0.004;

    const addRow = () => form.setData('payments', [...form.data.payments, { method: 'card', financial_account_id: '', amount: remaining > 0 ? remaining.toFixed(2) : '0' }]);
    const patchRow = (i: number, changes: Partial<PaymentRow>) => {
        const next = [...form.data.payments];
        next[i] = { ...next[i], ...changes };
        form.setData('payments', next);
    };

    const submit = () => {
        form.post(`/sales/${documentId}/post`, { preserveScroll: true });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 no-print">
            <div className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                <h2 className="mb-3 text-base font-bold text-ink-900">التحصيل — الإجمالي <Money value={grandTotal} /></h2>

                {(form.errors as Record<string, string>).message && <Alert>{(form.errors as Record<string, string>).message}</Alert>}

                {isCredit && !hasCustomer && (
                    <Alert tone="warning">المبلغ المدفوع أقل من الإجمالي؛ البيع الآجل يتطلب اختيار عميل قبل العودة لهذه الخطوة.</Alert>
                )}

                <div className="space-y-2">
                    {form.data.payments.map((row, i) => (
                        <div key={i} className="grid grid-cols-3 gap-2">
                            <Select value={row.method} onChange={(e) => patchRow(i, { method: e.target.value })}>
                                {Object.entries(methodLabels).map(([k, v]) => (
                                    <option key={k} value={k}>{v}</option>
                                ))}
                            </Select>
                            <Select value={String(row.financial_account_id)} onChange={(e) => patchRow(i, { financial_account_id: Number(e.target.value) })}>
                                <option value="">اختر حسابًا</option>
                                {accounts.map((a) => (
                                    <option key={a.id} value={a.id}>{a.name}</option>
                                ))}
                            </Select>
                            <NumberInput value={row.amount} onChange={(e) => patchRow(i, { amount: e.target.value })} />
                        </div>
                    ))}
                </div>

                <Button type="button" variant="ghost" size="sm" icon="plus" className="mt-2" onClick={addRow}>
                    وسيلة دفع أخرى (دفع مختلط)
                </Button>

                <div className="mt-3 flex items-center justify-between text-sm">
                    <span>المتبقي {isCredit ? '(آجل)' : ''}</span>
                    <Money value={remaining} />
                </div>

                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="ghost" onClick={onClose}>إلغاء</Button>
                    <Button loading={form.processing} disabled={isCredit && !hasCustomer} onClick={submit}>
                        تأكيد الترحيل
                    </Button>
                </div>
            </div>
        </div>
    );
}
