import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import Pagination from '@/components/Pagination';
import { Alert, Badge, Button, Card, Field, Select, Table } from '@/components/ui';
import { dateTimeAr } from '@/lib/format';
import type { Paginated, PageProps } from '@/types';

const statusTone: Record<string, 'slate' | 'green' | 'amber' | 'rose' | 'blue'> = {
    draft: 'amber',
    counting: 'blue',
    posted: 'green',
    cancelled: 'slate',
};

const statusLabelsAr: Record<string, string> = {
    draft: 'مسودة',
    counting: 'قيد الجرد',
    posted: 'معتمد',
    cancelled: 'ملغي',
};

interface StockCountRow {
    id: number;
    status: string;
    counted_at: string;
    warehouse: { id: number; name: string } | null;
    adjustment_document_id: number | null;
}

interface IndexProps extends PageProps {
    counts: Paginated<StockCountRow>;
    filters: { warehouse_id?: string; status?: string };
    warehouses: { id: number; name: string }[];
}

export default function StockCountsIndex() {
    const { counts, filters, warehouses, errors } = usePage<IndexProps>().props;
    const [creating, setCreating] = useState(false);

    return (
        <AppLayout
            title="الجرد"
            actions={
                <Button icon="plus" onClick={() => setCreating((v) => !v)}>
                    جلسة جرد جديدة
                </Button>
            }
        >
            {(errors as Record<string, string>).warehouse_id && <Alert>{(errors as Record<string, string>).warehouse_id}</Alert>}

            {creating && <NewCountForm warehouses={warehouses} onClose={() => setCreating(false)} />}

            <Card>
                <form
                    className="grid gap-3 sm:grid-cols-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const data = Object.fromEntries(new FormData(e.currentTarget as HTMLFormElement) as never);
                        router.get('/stock-counts', data, { preserveState: true, replace: true });
                    }}
                >
                    <Select name="warehouse_id" defaultValue={filters.warehouse_id ?? ''}>
                        <option value="">كل المخازن</option>
                        {warehouses.map((w) => (
                            <option key={w.id} value={w.id}>{w.name}</option>
                        ))}
                    </Select>
                    <Select name="status" defaultValue={filters.status ?? ''}>
                        <option value="">كل الحالات</option>
                        <option value="draft">مسودة</option>
                        <option value="counting">قيد الجرد</option>
                        <option value="posted">معتمد</option>
                        <option value="cancelled">ملغي</option>
                    </Select>
                    <Button type="submit" variant="secondary" icon="search">
                        بحث
                    </Button>
                </form>
            </Card>

            <Card title={`جلسات الجرد (${counts.total})`}>
                <Table head={['الرقم', 'المخزن', 'وقت البدء', 'الحالة', '']} empty={counts.data.length === 0}>
                    {counts.data.map((c) => (
                        <tr key={c.id}>
                            <td className="px-3 py-2 num">
                                <Link href={`/stock-counts/${c.id}`} className="text-brand-700 hover:underline">
                                    #{c.id}
                                </Link>
                            </td>
                            <td className="px-3 py-2">{c.warehouse?.name ?? '—'}</td>
                            <td className="px-3 py-2 num">{dateTimeAr(c.counted_at)}</td>
                            <td className="px-3 py-2">
                                <Badge tone={statusTone[c.status] ?? 'slate'}>{statusLabelsAr[c.status] ?? c.status}</Badge>
                            </td>
                            <td className="px-3 py-2">
                                <Link href={`/stock-counts/${c.id}`} className="text-sm text-brand-700 hover:underline">
                                    فتح
                                </Link>
                            </td>
                        </tr>
                    ))}
                </Table>

                <Pagination links={counts.links} />
            </Card>
        </AppLayout>
    );
}

function NewCountForm({ warehouses, onClose }: { warehouses: { id: number; name: string }[]; onClose: () => void }) {
    const form = useForm({
        warehouse_id: warehouses[0]?.id ?? ('' as number | ''),
        notes: '',
    });

    return (
        <Card title="جلسة جرد جديدة" action={<Button variant="ghost" size="sm" onClick={onClose}>إغلاق</Button>}>
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/stock-counts');
                }}
            >
                <Alert tone="warning">
                    إنشاء جلسة الجرد يُجمّد حركة هذا المخزن (بيع وشراء وتسويات) حتى اعتماد الجرد أو إلغاؤه.
                </Alert>

                <Field label="المخزن" required error={form.errors.warehouse_id}>
                    <Select value={String(form.data.warehouse_id)} onChange={(e) => form.setData('warehouse_id', Number(e.target.value))}>
                        {warehouses.map((w) => (
                            <option key={w.id} value={w.id}>{w.name}</option>
                        ))}
                    </Select>
                </Field>

                <Button type="submit" loading={form.processing}>
                    بدء الجرد
                </Button>
            </form>
        </Card>
    );
}
