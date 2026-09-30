import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Alert, Badge, Button, Card, Field, Money, NumberInput, Select, Table, TextInput } from '@/components/ui';
import { dateAr, einvoiceStatusLabels, methodLabels, qty, statusLabels } from '@/lib/format';
import type { PageProps } from '@/types';

interface SaleLine {
    id: number;
    description: string;
    sku_snapshot: string | null;
    unit_name_snapshot: string;
    quantity: string;
    base_quantity: string;
    unit_price: string;
    entered_unit_price: string;
    discount_amount: string;
    tax_rate_snapshot: string;
    net_amount: string;
    tax_amount: string;
    gross_amount: string;
    unit_cost_snapshot: string | null;
    item_type_snapshot?: string;
}

interface EinvoiceDoc {
    id: number;
    status: string;
    route: string;
    reporting_due_at: string | null;
}

interface ShowProps extends PageProps {
    document: {
        id: number;
        document_number: string | null;
        status: string;
        business_date: string;
        notes: string | null;
        subtotal: string;
        discount_total: string;
        net_total: string;
        tax_total: string;
        grand_total: string;
        einvoice_type: string;
        party: { id: number; name: string } | null;
        lines: SaleLine[];
        tax_totals: { category: string; rate: string; taxable_amount: string; tax_amount: string }[];
        allocations: { id: number; amount: string; payment: { payment_number: string; payment_date: string; method: string } }[];
        einvoice_document: EinvoiceDoc | null;
    };
    outstanding: string | null;
    returns: {
        id: number;
        document_number: string | null;
        status: string;
        business_date: string;
        grand_total: string;
        lines: { id: number; description: string; quantity: string; net_amount: string; original_line_id: number | null }[];
    }[];
    returnedByLine: Record<number, string>;
    einvoiceDevices: { id: number; serial_number: string; environment: string }[];
    financialAccounts: { id: number; name: string; kind: string }[];
}

export default function SaleShow() {
    const { document, outstanding, returns, returnedByLine, einvoiceDevices, financialAccounts } = usePage<ShowProps>().props;
    const [printMode, setPrintMode] = useState<'thermal' | 'a4'>('a4');

    return (
        <AppLayout
            title={`فاتورة بيع ${document.document_number ?? `(مسودة #${document.id})`}`}
            actions={
                <div className="flex gap-2">
                    <Select value={printMode} onChange={(e) => setPrintMode(e.target.value as 'thermal' | 'a4')} className="w-40">
                        <option value="a4">طباعة A4</option>
                        <option value="thermal">إيصال 80مم</option>
                    </Select>
                    <Button variant="secondary" icon="print" onClick={() => window.print()}>
                        طباعة / إعادة طباعة
                    </Button>
                </div>
            }
        >
            <style>{`
                @media print {
                    body.print-thermal main { width: 80mm; font-size: 11px; }
                    body.print-thermal .print-hide { display: none !important; }
                }
            `}</style>
            <script dangerouslySetInnerHTML={{ __html: `document.body.classList.toggle('print-thermal', ${printMode === 'thermal'});` }} />

            <Card>
                <dl className="grid gap-4 text-sm sm:grid-cols-4">
                    <Info label="العميل" value={document.party?.name ?? 'نقدي'} />
                    <Info label="التاريخ" value={dateAr(document.business_date)} />
                    <Info label="الحالة" value={<Badge tone={document.status === 'posted' ? 'green' : 'amber'}>{statusLabels[document.status]}</Badge>} />
                    <Info label="الإجمالي" value={<Money value={document.grand_total} />} />
                    {outstanding !== null && <Info label="المتبقي" value={<Money value={outstanding} />} />}
                    {document.einvoice_type !== 'none' && (
                        <Info
                            label="الفوترة الإلكترونية"
                            value={<EinvoiceBadgeAndActions documentId={document.id} einvoice={document.einvoice_document} devices={einvoiceDevices} />}
                        />
                    )}
                </dl>
            </Card>

            <Card title="السطور">
                <Table head={['الوصف', 'الوحدة', 'الكمية', 'سعر الوحدة', 'الخصم', 'الضريبة %', 'الصافي', 'الضريبة', 'الإجمالي']}>
                    {document.lines.map((line) => (
                        <tr key={line.id}>
                            <td className="px-3 py-2">
                                <span className="block">{line.description}</span>
                                {line.sku_snapshot && <span className="num text-xs text-slate-500">{line.sku_snapshot}</span>}
                            </td>
                            <td className="px-3 py-2">{line.unit_name_snapshot}</td>
                            <td className="px-3 py-2 num">{qty(line.quantity)}</td>
                            <td className="px-3 py-2"><Money value={line.entered_unit_price} /></td>
                            <td className="px-3 py-2"><Money value={line.discount_amount} /></td>
                            <td className="px-3 py-2 num">{Number(line.tax_rate_snapshot).toFixed(2)}</td>
                            <td className="px-3 py-2"><Money value={line.net_amount} /></td>
                            <td className="px-3 py-2"><Money value={line.tax_amount} /></td>
                            <td className="px-3 py-2 font-semibold"><Money value={line.gross_amount} /></td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2 print-hide">
                <Card title="تجميع الضريبة">
                    <Table head={['التصنيف', 'النسبة %', 'الوعاء', 'الضريبة']} empty={document.tax_totals.length === 0}>
                        {document.tax_totals.map((t, i) => (
                            <tr key={i}>
                                <td className="px-3 py-2">{t.category}</td>
                                <td className="px-3 py-2 num">{Number(t.rate).toFixed(2)}</td>
                                <td className="px-3 py-2"><Money value={t.taxable_amount} /></td>
                                <td className="px-3 py-2"><Money value={t.tax_amount} /></td>
                            </tr>
                        ))}
                    </Table>
                </Card>

                <Card title="التحصيل">
                    <Table head={['السند', 'التاريخ', 'الطريقة', 'المبلغ']} empty={document.allocations.length === 0}>
                        {document.allocations.map((a) => (
                            <tr key={a.id}>
                                <td className="px-3 py-2 num">{a.payment.payment_number}</td>
                                <td className="px-3 py-2 num">{dateAr(a.payment.payment_date)}</td>
                                <td className="px-3 py-2">{methodLabels[a.payment.method] ?? a.payment.method}</td>
                                <td className="px-3 py-2"><Money value={a.amount} /></td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>

            {document.status === 'posted' && (
                <div className="print-hide">
                    <ReturnsSection document={document} returns={returns} returnedByLine={returnedByLine} financialAccounts={financialAccounts} />
                </div>
            )}
        </AppLayout>
    );
}

function EinvoiceBadgeAndActions({
    documentId,
    einvoice,
    devices,
}: {
    documentId: number;
    einvoice: EinvoiceDoc | null;
    devices: { id: number; serial_number: string; environment: string }[];
}) {
    const genForm = useForm({ device_id: devices[0]?.id ?? '' });
    const tone = einvoice?.status === 'rejected' ? 'rose' : einvoice?.status === 'reported' || einvoice?.status === 'cleared' ? 'green' : 'amber';

    if (!einvoice) {
        return (
            <div className="flex items-center gap-2">
                <Badge tone="slate">لم تُولَّد بعد</Badge>
                {devices.length > 0 && (
                    <Button
                        size="sm"
                        variant="secondary"
                        loading={genForm.processing}
                        onClick={() => genForm.post(`/documents/${documentId}/einvoice`)}
                    >
                        توليد (محاكاة)
                    </Button>
                )}
            </div>
        );
    }

    return (
        <div className="flex items-center gap-2">
            <Badge tone={tone}>{einvoiceStatusLabels[einvoice.status] ?? einvoice.status}</Badge>
            {(einvoice.status === 'rejected' || einvoice.status === 'connection_error') && (
                <RetryButton einvoiceId={einvoice.id} />
            )}
        </div>
    );
}

function RetryButton({ einvoiceId }: { einvoiceId: number }) {
    const form = useForm({});
    return (
        <Button size="sm" variant="secondary" loading={form.processing} onClick={() => form.post(`/einvoicing/${einvoiceId}/retry`)}>
            إعادة المحاولة
        </Button>
    );
}

function ReturnsSection({
    document,
    returns,
    returnedByLine,
    financialAccounts,
}: {
    document: ShowProps['document'];
    returns: ShowProps['returns'];
    returnedByLine: ShowProps['returnedByLine'];
    financialAccounts: ShowProps['financialAccounts'];
}) {
    const [creating, setCreating] = useState(false);
    const returnableLines = document.lines.filter((l) => l.unit_cost_snapshot !== null);

    return (
        <Card
            title="مرتجعات البيع"
            action={
                <Button size="sm" icon="plus" onClick={() => setCreating((v) => !v)}>
                    مرتجع جديد
                </Button>
            }
        >
            {creating && (
                <ReturnForm document={document} lines={returnableLines} returnedByLine={returnedByLine} financialAccounts={financialAccounts} onClose={() => setCreating(false)} />
            )}

            <Table head={['الرقم', 'التاريخ', 'عدد السطور', 'القيمة', 'الحالة']} empty={returns.length === 0}>
                {returns.map((r) => (
                    <tr key={r.id}>
                        <td className="px-3 py-2 num">{r.document_number ?? `مسودة #${r.id}`}</td>
                        <td className="px-3 py-2 num">{dateAr(r.business_date)}</td>
                        <td className="px-3 py-2 num">{r.lines.length}</td>
                        <td className="px-3 py-2"><Money value={r.grand_total} /></td>
                        <td className="px-3 py-2">
                            <Badge tone={r.status === 'posted' ? 'green' : 'amber'}>{statusLabels[r.status]}</Badge>
                        </td>
                    </tr>
                ))}
            </Table>
        </Card>
    );
}

interface ReturnLineDraft {
    original_line_id: number;
    description: string;
    quantity: string;
    unit_price: string;
    discount_amount: string;
    selected: boolean;
    max: string;
}

function ReturnForm({
    document,
    lines,
    returnedByLine,
    financialAccounts,
    onClose,
}: {
    document: ShowProps['document'];
    lines: SaleLine[];
    returnedByLine: ShowProps['returnedByLine'];
    financialAccounts: ShowProps['financialAccounts'];
    onClose: () => void;
}) {
    const initialLines: ReturnLineDraft[] = lines.map((l) => {
        const returned = Number(returnedByLine[l.id] ?? '0');
        const remaining = Math.max(0, Number(l.quantity) - returned);
        return {
            original_line_id: l.id,
            description: l.description,
            quantity: remaining.toString(),
            unit_price: l.entered_unit_price,
            discount_amount: '0',
            selected: false,
            max: remaining.toString(),
        };
    });

    const form = useForm({
        business_date: new Date().toISOString().slice(0, 10),
        reason: '',
        notes: '',
        lines: initialLines,
        refund: { amount: '', method: 'cash', financial_account_id: '' as number | '' },
    });

    const patch = (index: number, changes: Partial<ReturnLineDraft>) => {
        const next = [...form.data.lines];
        next[index] = { ...next[index], ...changes };
        form.setData('lines', next);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const payloadLines = form.data.lines
            .filter((l) => l.selected && Number(l.quantity) > 0)
            .map((l) => ({
                original_line_id: l.original_line_id,
                description: l.description,
                quantity: l.quantity,
                unit_price: l.unit_price,
                discount_amount: l.discount_amount,
            }));

        form.transform((data) => ({
            ...data,
            lines: payloadLines,
            refund: data.refund.amount ? data.refund : undefined,
        }));
        form.post(`/sales/${document.id}/returns`, {
            onSuccess: () => onClose(),
            onError: () => form.transform((data) => data),
        });
    };

    const errorMessage = (form.errors as Record<string, string>).lines || (form.errors as Record<string, string>).sale || (form.errors as Record<string, string>).message;

    return (
        <Card title="إنشاء مرتجع بيع" className="mb-4" action={<Button variant="ghost" size="sm" onClick={onClose}>إغلاق</Button>}>
            <form className="space-y-4" onSubmit={submit}>
                {errorMessage && <Alert>{errorMessage}</Alert>}

                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="تاريخ المرتجع" required>
                        <TextInput type="date" value={form.data.business_date} onChange={(e) => form.setData('business_date', e.target.value)} />
                    </Field>
                    <Field label="سبب الإرجاع">
                        <TextInput value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                    <Field label="ملاحظات">
                        <TextInput value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </Field>
                </div>

                <div className="space-y-2">
                    {form.data.lines.map((line, index) => {
                        const remaining = Number(line.max);
                        if (remaining <= 0) return null;

                        return (
                            <div key={line.original_line_id} className="grid items-end gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-6">
                                <label className="flex items-center gap-2 sm:col-span-2">
                                    <input type="checkbox" checked={line.selected} onChange={(e) => patch(index, { selected: e.target.checked })} />
                                    <span className="text-sm">{line.description}</span>
                                </label>
                                <Field label={`الكمية (المتبقي ${remaining})`}>
                                    <NumberInput value={line.quantity} onChange={(e) => patch(index, { quantity: e.target.value })} disabled={!line.selected} />
                                </Field>
                                <Field label="سعر الإرجاع">
                                    <NumberInput value={line.unit_price} onChange={(e) => patch(index, { unit_price: e.target.value })} disabled={!line.selected} />
                                </Field>
                                <Field label="خصم">
                                    <NumberInput value={line.discount_amount} onChange={(e) => patch(index, { discount_amount: e.target.value })} disabled={!line.selected} />
                                </Field>
                            </div>
                        );
                    })}
                </div>

                <Card title="رد نقدي فوري (اختياري)">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field label="المبلغ">
                            <NumberInput
                                value={form.data.refund.amount}
                                onChange={(e) => form.setData('refund', { ...form.data.refund, amount: e.target.value })}
                            />
                        </Field>
                        <Field label="الطريقة">
                            <Select
                                value={form.data.refund.method}
                                onChange={(e) => form.setData('refund', { ...form.data.refund, method: e.target.value })}
                            >
                                {Object.entries(methodLabels).map(([k, v]) => (
                                    <option key={k} value={k}>{v}</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="من/إلى حساب">
                            <Select
                                value={String(form.data.refund.financial_account_id)}
                                onChange={(e) => form.setData('refund', { ...form.data.refund, financial_account_id: Number(e.target.value) || '' })}
                            >
                                <option value="">اختر حسابًا</option>
                                {financialAccounts.map((a) => (
                                    <option key={a.id} value={a.id}>{a.name}</option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                </Card>

                <Button type="submit" loading={form.processing} disabled={!form.data.lines.some((l) => l.selected)}>
                    ترحيل المرتجع
                </Button>
            </form>
        </Card>
    );
}

function Info({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="font-medium text-ink-900">{value}</dd>
        </div>
    );
}
