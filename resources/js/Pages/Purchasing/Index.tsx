import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import Pagination from '@/components/Pagination';
import LinesEditor, { emptyLine, type ItemOption, type LineDraft } from '@/components/documents/LinesEditor';
import { Alert, Badge, Button, Card, Field, Money, Select, Table, TextInput } from '@/components/ui';
import { dateAr, statusLabels } from '@/lib/format';
import type { Paginated, PageProps } from '@/types';

interface PurchaseDoc {
    id: number;
    document_number: string | null;
    status: string;
    business_date: string;
    due_date: string | null;
    supplier_reference: string | null;
    net_total: string;
    tax_total: string;
    grand_total: string;
    party: { id: number; name: string } | null;
}

interface PurchasesProps extends PageProps {
    documents: Paginated<PurchaseDoc>;
    outstanding: Record<number, string>;
    filters: { q?: string; status?: string; from?: string; to?: string };
    options: {
        suppliers: { id: number; name: string }[];
        warehouses: { id: number; name: string }[];
        financial_accounts: { id: number; name: string; kind: string }[];
        items: ItemOption[];
    };
}

export default function PurchasesIndex() {
    const { documents, outstanding, filters, options, errors } = usePage<PurchasesProps>().props;
    const [creating, setCreating] = useState(false);

    return (
        <AppLayout
            title="المشتريات"
            actions={
                <Button icon="plus" onClick={() => setCreating((v) => !v)}>
                    فاتورة شراء جديدة
                </Button>
            }
        >
            {errors.message && <Alert>{errors.message}</Alert>}

            {creating && <PurchaseForm options={options} onClose={() => setCreating(false)} />}

            <Card>
                <form
                    className="grid gap-3 sm:grid-cols-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const data = Object.fromEntries(new FormData(e.currentTarget as HTMLFormElement) as never);
                        router.get('/purchases', data, { preserveState: true, replace: true });
                    }}
                >
                    <TextInput name="q" placeholder="رقم الفاتورة أو مرجع المورد" defaultValue={filters.q ?? ''} />
                    <Select name="status" defaultValue={filters.status ?? ''}>
                        <option value="">كل الحالات</option>
                        <option value="draft">مسودة</option>
                        <option value="posted">مرحّلة</option>
                    </Select>
                    <TextInput name="from" type="date" defaultValue={filters.from ?? ''} />
                    <TextInput name="to" type="date" defaultValue={filters.to ?? ''} />
                    <Button type="submit" variant="secondary" icon="search">
                        بحث
                    </Button>
                </form>
            </Card>

            <Card title={`فواتير الشراء (${documents.total})`}>
                <Table
                    head={['الرقم', 'المورد', 'التاريخ', 'مرجع المورد', 'الصافي', 'الضريبة', 'الإجمالي', 'المتبقي', 'الحالة', '']}
                    empty={documents.data.length === 0}
                >
                    {documents.data.map((doc) => (
                        <tr key={doc.id}>
                            <td className="px-3 py-2 num">
                                <Link href={`/purchases/${doc.id}`} className="text-brand-700 hover:underline">
                                    {doc.document_number ?? `مسودة #${doc.id}`}
                                </Link>
                            </td>
                            <td className="px-3 py-2">{doc.party?.name ?? '—'}</td>
                            <td className="px-3 py-2 num">{dateAr(doc.business_date)}</td>
                            <td className="px-3 py-2 num">{doc.supplier_reference ?? '—'}</td>
                            <td className="px-3 py-2"><Money value={doc.net_total} /></td>
                            <td className="px-3 py-2"><Money value={doc.tax_total} /></td>
                            <td className="px-3 py-2 font-semibold"><Money value={doc.grand_total} /></td>
                            <td className="px-3 py-2"><Money value={outstanding[doc.id]} /></td>
                            <td className="px-3 py-2">
                                <Badge tone={doc.status === 'posted' ? 'green' : doc.status === 'draft' ? 'amber' : 'slate'}>
                                    {statusLabels[doc.status]}
                                </Badge>
                            </td>
                            <td className="px-3 py-2">
                                {doc.status === 'draft' && <PostButton documentId={doc.id} accounts={options.financial_accounts} />}
                            </td>
                        </tr>
                    ))}
                </Table>

                <Pagination links={documents.links} />
            </Card>
        </AppLayout>
    );
}

function PostButton({ documentId, accounts }: { documentId: number; accounts: { id: number; name: string; kind: string }[] }) {
    const form = useForm<{ payment: { amount: string; method: string; financial_account_id: number | '' } }>({
        payment: { amount: '', method: 'cash', financial_account_id: accounts[0]?.id ?? '' },
    });
    const [open, setOpen] = useState(false);

    if (!open) {
        return (
            <Button size="sm" onClick={() => setOpen(true)}>
                ترحيل واستلام
            </Button>
        );
    }

    return (
        <div className="flex flex-wrap items-end gap-2">
            <Field label="مدفوع الآن (اختياري)">
                <TextInput
                    dir="ltr"
                    className="num w-28"
                    value={form.data.payment.amount}
                    onChange={(e) => form.setData('payment', { ...form.data.payment, amount: e.target.value })}
                />
            </Field>
            <Field label="من حساب">
                <Select
                    value={String(form.data.payment.financial_account_id)}
                    onChange={(e) => form.setData('payment', { ...form.data.payment, financial_account_id: Number(e.target.value) })}
                >
                    {accounts.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.name}
                        </option>
                    ))}
                </Select>
            </Field>
            <Button
                size="sm"
                loading={form.processing}
                onClick={() =>
                    form.post(`/purchases/${documentId}/post`, {
                        preserveScroll: true,
                        onSuccess: () => setOpen(false),
                    })
                }
            >
                تأكيد الترحيل
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                إلغاء
            </Button>
        </div>
    );
}

function PurchaseForm({ options, onClose }: { options: PurchasesProps['options']; onClose: () => void }) {
    const form = useForm({
        party_id: options.suppliers[0]?.id ?? ('' as number | ''),
        warehouse_id: options.warehouses[0]?.id ?? ('' as number | ''),
        business_date: new Date().toISOString().slice(0, 10),
        due_date: '',
        supplier_reference: '',
        document_discount: '0',
        notes: '',
        idempotency_key: crypto.randomUUID(),
        lines: [emptyLine()] as LineDraft[],
    });

    return (
        <Card title="فاتورة شراء جديدة" action={<Button variant="ghost" onClick={onClose}>إغلاق</Button>}>
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/purchases/drafts', { onSuccess: () => onClose() });
                }}
            >
                <Alert tone="info">اعتماد فاتورة شراء البضاعة في هذه النسخة يعني استلامها للمخزن مباشرة.</Alert>

                <div className="grid gap-4 sm:grid-cols-5">
                    <Field label="المورد" required error={form.errors.party_id}>
                        <Select value={String(form.data.party_id)} onChange={(e) => form.setData('party_id', Number(e.target.value))}>
                            <option value="">اختر موردًا</option>
                            {options.suppliers.map((s) => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="المخزن" required>
                        <Select value={String(form.data.warehouse_id)} onChange={(e) => form.setData('warehouse_id', Number(e.target.value))}>
                            {options.warehouses.map((w) => (
                                <option key={w.id} value={w.id}>{w.name}</option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="تاريخ الفاتورة" required>
                        <TextInput type="date" value={form.data.business_date} onChange={(e) => form.setData('business_date', e.target.value)} />
                    </Field>
                    <Field label="تاريخ الاستحقاق">
                        <TextInput type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                    </Field>
                    <Field label="رقم فاتورة المورد">
                        <TextInput dir="ltr" value={form.data.supplier_reference} onChange={(e) => form.setData('supplier_reference', e.target.value)} />
                    </Field>
                </div>

                <LinesEditor lines={form.data.lines} items={options.items} onChange={(lines) => form.setData('lines', lines)} priceLabel="سعر الشراء" />

                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="خصم الفاتورة" hint="يوزَّع على السطور بنسبة قيمتها.">
                        <TextInput dir="ltr" className="num" value={form.data.document_discount} onChange={(e) => form.setData('document_discount', e.target.value)} />
                    </Field>
                    <Field label="ملاحظات">
                        <TextInput value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </Field>
                </div>

                <Button type="submit" loading={form.processing}>
                    حفظ كمسودة
                </Button>
            </form>
        </Card>
    );
}
