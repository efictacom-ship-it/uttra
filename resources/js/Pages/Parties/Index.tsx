import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import Pagination from '@/components/Pagination';
import { Badge, Button, Card, Field, Money, Select, Table, TextInput } from '@/components/ui';
import type { Paginated, PageProps } from '@/types';

interface PartyRecord {
    id: number;
    name: string;
    is_customer: boolean;
    is_supplier: boolean;
    phone: string | null;
    vat_number: string | null;
    credit_limit: string | null;
    is_active: boolean;
}

interface PartiesProps extends PageProps {
    parties: Paginated<PartyRecord>;
    filters: { q?: string; role?: string };
    balances: Record<number, { receivable: string; payable: string }>;
}

export default function PartiesIndex() {
    const { parties, filters, balances } = usePage<PartiesProps>().props;
    const [editing, setEditing] = useState<PartyRecord | null>(null);
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState(filters.q ?? '');

    return (
        <AppLayout
            title="العملاء والموردون"
            actions={
                <Button icon="plus" onClick={() => { setEditing(null); setOpen(true); }}>
                    طرف جديد
                </Button>
            }
        >
            <Card>
                <form
                    className="grid gap-3 sm:grid-cols-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/parties', { ...filters, q }, { preserveState: true, replace: true });
                    }}
                >
                    <TextInput placeholder="بحث بالاسم أو الجوال أو الرقم الضريبي" value={q} onChange={(e) => setQ(e.target.value)} />
                    <Select value={filters.role ?? ''} onChange={(e) => router.get('/parties', { ...filters, role: e.target.value }, { preserveState: true, replace: true })}>
                        <option value="">الكل</option>
                        <option value="customer">العملاء</option>
                        <option value="supplier">الموردون</option>
                    </Select>
                    <Button type="submit" variant="secondary" icon="search">بحث</Button>
                </form>
            </Card>

            {(open || editing) && <PartyForm party={editing} onClose={() => { setOpen(false); setEditing(null); }} />}

            <Card title={`الأطراف (${parties.total})`}>
                <Table head={['الاسم', 'النوع', 'الجوال', 'الرقم الضريبي', 'رصيد مدين', 'رصيد دائن', 'الحالة', '']} empty={parties.data.length === 0}>
                    {parties.data.map((party) => (
                        <tr key={party.id}>
                            <td className="px-3 py-2 font-medium">
                                <Link href={`/parties/${party.id}`} className="text-brand-700 hover:underline">
                                    {party.name}
                                </Link>
                            </td>
                            <td className="px-3 py-2 text-xs">
                                {party.is_customer && <Badge tone="blue">عميل</Badge>} {party.is_supplier && <Badge tone="amber">مورد</Badge>}
                            </td>
                            <td className="px-3 py-2 num">{party.phone ?? '—'}</td>
                            <td className="px-3 py-2 num">{party.vat_number ?? '—'}</td>
                            <td className="px-3 py-2"><Money value={balances[party.id]?.receivable} /></td>
                            <td className="px-3 py-2"><Money value={balances[party.id]?.payable} /></td>
                            <td className="px-3 py-2"><Badge tone={party.is_active ? 'green' : 'slate'}>{party.is_active ? 'نشط' : 'موقوف'}</Badge></td>
                            <td className="px-3 py-2">
                                <Button size="sm" variant="secondary" onClick={() => { setEditing(party); setOpen(false); }}>تعديل</Button>
                            </td>
                        </tr>
                    ))}
                </Table>

                <Pagination links={parties.links} />
            </Card>
        </AppLayout>
    );
}

function PartyForm({ party, onClose }: { party: PartyRecord | null; onClose: () => void }) {
    const form = useForm({
        name: party?.name ?? '',
        is_customer: party?.is_customer ?? true,
        is_supplier: party?.is_supplier ?? false,
        phone: party?.phone ?? '',
        vat_number: party?.vat_number ?? '',
        registration_number: '',
        credit_limit: party?.credit_limit ?? '',
        is_active: party?.is_active ?? true,
    });

    return (
        <Card title={party ? `تعديل: ${party.name}` : 'طرف جديد'} action={<Button variant="ghost" onClick={onClose}>إغلاق</Button>}>
            <form
                className="grid gap-4 sm:grid-cols-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    const opts = { preserveScroll: true, onSuccess: () => onClose() };
                    party ? form.patch(`/parties/${party.id}`, opts) : form.post('/parties', opts);
                }}
            >
                <Field label="الاسم" required error={form.errors.name}>
                    <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </Field>
                <Field label="عميل؟">
                    <Select value={form.data.is_customer ? '1' : '0'} onChange={(e) => form.setData('is_customer', e.target.value === '1')}>
                        <option value="1">نعم</option>
                        <option value="0">لا</option>
                    </Select>
                </Field>
                <Field label="مورد؟">
                    <Select value={form.data.is_supplier ? '1' : '0'} onChange={(e) => form.setData('is_supplier', e.target.value === '1')}>
                        <option value="1">نعم</option>
                        <option value="0">لا</option>
                    </Select>
                </Field>
                <Field label="الجوال">
                    <TextInput dir="ltr" value={form.data.phone ?? ''} onChange={(e) => form.setData('phone', e.target.value)} />
                </Field>
                <Field label="الرقم الضريبي" error={form.errors.vat_number} hint="مطلوب للفاتورة القياسية للعميل التجاري.">
                    <TextInput dir="ltr" className="num" value={form.data.vat_number ?? ''} onChange={(e) => form.setData('vat_number', e.target.value)} />
                </Field>
                <Field label="حد الائتمان" hint="يُستخدم للتنبيه عند البيع الآجل.">
                    <TextInput dir="ltr" className="num" value={form.data.credit_limit ?? ''} onChange={(e) => form.setData('credit_limit', e.target.value)} />
                </Field>

                <div className="sm:col-span-3">
                    <Button type="submit" loading={form.processing}>{party ? 'حفظ' : 'إضافة'}</Button>
                </div>
            </form>
        </Card>
    );
}
