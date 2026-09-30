import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Alert, Badge, Button, Card, Field, Money, NumberInput, Table, TextInput } from '@/components/ui';
import { dateAr, qty, statusLabels } from '@/lib/format';
import type { PageProps } from '@/types';

interface PurchaseLine {
    id: number;
    description: string;
    sku_snapshot: string | null;
    unit_name_snapshot: string;
    quantity: string;
    to_base_factor: string;
    base_quantity: string;
    unit_price: string;
    discount_amount: string;
    tax_rate_snapshot: string;
    net_amount: string;
    tax_amount: string;
    gross_amount: string;
    unit_cost_snapshot: string | null;
    item_type_snapshot?: string;
}

interface ShowProps extends PageProps {
    document: {
        id: number;
        document_number: string | null;
        status: string;
        business_date: string;
        due_date: string | null;
        supplier_reference: string | null;
        notes: string | null;
        subtotal: string;
        discount_total: string;
        net_total: string;
        tax_total: string;
        grand_total: string;
        party: { id: number; name: string } | null;
        lines: PurchaseLine[];
        tax_totals: { category: string; rate: string; taxable_amount: string; tax_amount: string }[];
        allocations: { id: number; amount: string; payment: { payment_number: string; payment_date: string; method: string } }[];
    };
    outstanding: string;
    returns: {
        id: number;
        document_number: string | null;
        status: string;
        business_date: string;
        grand_total: string;
        lines: { id: number; description: string; quantity: string; net_amount: string; original_line_id: number | null }[];
    }[];
    returnedByLine: Record<number, string>;
}

export default function PurchaseShow() {
    const { document, outstanding, returns, returnedByLine } = usePage<ShowProps>().props;

    return (
        <AppLayout
            title={`فاتورة شراء ${document.document_number ?? `(مسودة #${document.id})`}`}
            actions={
                <Button variant="secondary" icon="print" onClick={() => window.print()}>
                    طباعة
                </Button>
            }
        >
            <Card>
                <dl className="grid gap-4 text-sm sm:grid-cols-4">
                    <Info label="المورد" value={document.party?.name ?? '—'} />
                    <Info label="التاريخ" value={dateAr(document.business_date)} />
                    <Info label="الاستحقاق" value={dateAr(document.due_date)} />
                    <Info label="مرجع المورد" value={document.supplier_reference ?? '—'} />
                    <Info label="الحالة" value={<Badge tone={document.status === 'posted' ? 'green' : 'amber'}>{statusLabels[document.status]}</Badge>} />
                    <Info label="الإجمالي" value={<Money value={document.grand_total} />} />
                    <Info label="المتبقي" value={<Money value={outstanding} />} />
                </dl>
            </Card>

            <Card title="السطور">
                <Table head={['الوصف', 'الوحدة', 'الكمية', 'بالوحدة الأساسية', 'سعر الوحدة', 'الخصم', 'الضريبة %', 'الصافي', 'الضريبة', 'الإجمالي']}>
                    {document.lines.map((line) => (
                        <tr key={line.id}>
                            <td className="px-3 py-2">
                                <span className="block">{line.description}</span>
                                {line.sku_snapshot && <span className="num text-xs text-slate-500">{line.sku_snapshot}</span>}
                            </td>
                            <td className="px-3 py-2">{line.unit_name_snapshot}</td>
                            <td className="px-3 py-2 num">{qty(line.quantity)}</td>
                            <td className="px-3 py-2 num text-slate-500">{qty(line.base_quantity)}</td>
                            <td className="px-3 py-2"><Money value={line.unit_price} /></td>
                            <td className="px-3 py-2"><Money value={line.discount_amount} /></td>
                            <td className="px-3 py-2 num">{Number(line.tax_rate_snapshot).toFixed(2)}</td>
                            <td className="px-3 py-2"><Money value={line.net_amount} /></td>
                            <td className="px-3 py-2"><Money value={line.tax_amount} /></td>
                            <td className="px-3 py-2 font-semibold"><Money value={line.gross_amount} /></td>
                        </tr>
                    ))}
                </Table>
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
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

                <Card title="السداد">
                    <Table head={['السند', 'التاريخ', 'المبلغ']} empty={document.allocations.length === 0}>
                        {document.allocations.map((a) => (
                            <tr key={a.id}>
                                <td className="px-3 py-2 num">{a.payment.payment_number}</td>
                                <td className="px-3 py-2 num">{dateAr(a.payment.payment_date)}</td>
                                <td className="px-3 py-2"><Money value={a.amount} /></td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>

            {document.status === 'posted' && (
                <ReturnsSection document={document} returns={returns} returnedByLine={returnedByLine} />
            )}
        </AppLayout>
    );
}

function ReturnsSection({
    document,
    returns,
    returnedByLine,
}: {
    document: ShowProps['document'];
    returns: ShowProps['returns'];
    returnedByLine: ShowProps['returnedByLine'];
}) {
    const [creating, setCreating] = useState(false);
    const returnableLines = document.lines.filter((l) => l.unit_cost_snapshot !== null);

    return (
        <Card
            title="مرتجعات الشراء"
            action={
                <Button size="sm" icon="plus" onClick={() => setCreating((v) => !v)}>
                    مرتجع جديد
                </Button>
            }
        >
            {creating && (
                <ReturnForm
                    document={document}
                    lines={returnableLines}
                    returnedByLine={returnedByLine}
                    onClose={() => setCreating(false)}
                />
            )}

            <Table
                head={['الرقم', 'التاريخ', 'عدد السطور', 'القيمة', 'الحالة']}
                empty={returns.length === 0}
            >
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
    onClose,
}: {
    document: ShowProps['document'];
    lines: PurchaseLine[];
    returnedByLine: ShowProps['returnedByLine'];
    onClose: () => void;
}) {
    const initialLines: ReturnLineDraft[] = lines.map((l) => {
        const returned = Number(returnedByLine[l.id] ?? '0');
        const remaining = Math.max(0, Number(l.quantity) - returned);
        return {
            original_line_id: l.id,
            description: l.description,
            quantity: remaining.toString(),
            unit_price: l.unit_price,
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

        form.transform((data) => ({ ...data, lines: payloadLines }));
        form.post(`/purchases/${document.id}/returns`, {
            onSuccess: () => onClose(),
            onError: () => form.transform((data) => data),
        });
    };

    const errorMessage = (form.errors as Record<string, string>).lines || (form.errors as Record<string, string>).purchase;

    return (
        <Card title="إنشاء مرتجع شراء" className="mb-4" action={<Button variant="ghost" size="sm" onClick={onClose}>إغلاق</Button>}>
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
                                    <input
                                        type="checkbox"
                                        checked={line.selected}
                                        onChange={(e) => patch(index, { selected: e.target.checked })}
                                    />
                                    <span className="text-sm">{line.description}</span>
                                </label>
                                <Field label={`الكمية (المتبقي ${remaining})`}>
                                    <NumberInput
                                        value={line.quantity}
                                        onChange={(e) => patch(index, { quantity: e.target.value })}
                                        disabled={!line.selected}
                                    />
                                </Field>
                                <Field label="سعر الإرجاع">
                                    <NumberInput
                                        value={line.unit_price}
                                        onChange={(e) => patch(index, { unit_price: e.target.value })}
                                        disabled={!line.selected}
                                    />
                                </Field>
                                <Field label="خصم">
                                    <NumberInput
                                        value={line.discount_amount}
                                        onChange={(e) => patch(index, { discount_amount: e.target.value })}
                                        disabled={!line.selected}
                                    />
                                </Field>
                            </div>
                        );
                    })}
                </div>

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
