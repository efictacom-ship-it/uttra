import { router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Alert, Badge, Button, Card, NumberInput, Table } from '@/components/ui';
import { dateTimeAr, qty } from '@/lib/format';
import type { PageProps } from '@/types';

const statusLabelsAr: Record<string, string> = {
    draft: 'مسودة',
    counting: 'قيد الجرد',
    posted: 'معتمد',
    cancelled: 'ملغي',
};

interface StockCountLineRow {
    id: number;
    item_id: number;
    expected_quantity: string;
    counted_quantity: string | null;
    unit_cost_snapshot: string;
    item: { id: number; sku: string; name_ar: string } | null;
}

interface ShowProps extends PageProps {
    stockCount: {
        id: number;
        status: string;
        counted_at: string;
        warehouse: { id: number; name: string } | null;
        adjustment_document: { id: number; document_number: string | null } | null;
        lines: StockCountLineRow[];
    };
}

export default function StockCountShow() {
    const { stockCount, errors } = usePage<ShowProps>().props;
    const active = stockCount.status === 'draft' || stockCount.status === 'counting';

    const form = useForm<{ lines: Record<number, string> }>({
        lines: Object.fromEntries(stockCount.lines.map((l) => [l.item_id, l.counted_quantity ?? ''])),
    });

    const saveCounts = () => {
        router.patch(
            `/stock-counts/${stockCount.id}`,
            {
                lines: Object.entries(form.data.lines).map(([item_id, counted_quantity]) => ({
                    item_id: Number(item_id),
                    counted_quantity: counted_quantity === '' ? null : counted_quantity,
                })),
            },
            { preserveScroll: true }
        );
    };

    return (
        <AppLayout
            title={`جلسة جرد #${stockCount.id}`}
            actions={
                active ? (
                    <div className="flex gap-2">
                        <Button variant="secondary" size="sm" onClick={() => saveCounts()}>
                            حفظ الكميات
                        </Button>
                        <Button
                            size="sm"
                            onClick={() => {
                                saveCounts();
                                router.post(`/stock-counts/${stockCount.id}/post`, {}, { preserveScroll: true });
                            }}
                        >
                            اعتماد الجرد
                        </Button>
                        <Button
                            variant="danger"
                            size="sm"
                            onClick={() => {
                                if (confirm('هل تريد إلغاء جلسة الجرد؟')) {
                                    router.post(`/stock-counts/${stockCount.id}/cancel`, {}, { preserveScroll: true });
                                }
                            }}
                        >
                            إلغاء
                        </Button>
                    </div>
                ) : undefined
            }
        >
            {(errors as Record<string, string>).lines && <Alert>{(errors as Record<string, string>).lines}</Alert>}
            {(errors as Record<string, string>).status && <Alert>{(errors as Record<string, string>).status}</Alert>}

            <Card>
                <dl className="grid gap-4 text-sm sm:grid-cols-4">
                    <Info label="المخزن" value={stockCount.warehouse?.name ?? '—'} />
                    <Info label="وقت البدء" value={dateTimeAr(stockCount.counted_at)} />
                    <Info label="الحالة" value={<Badge tone={stockCount.status === 'posted' ? 'green' : stockCount.status === 'cancelled' ? 'slate' : 'amber'}>{statusLabelsAr[stockCount.status]}</Badge>} />
                    <Info
                        label="مستند التسوية"
                        value={stockCount.adjustment_document ? (stockCount.adjustment_document.document_number ?? `#${stockCount.adjustment_document.id}`) : '—'}
                    />
                </dl>
            </Card>

            {active && (
                <Alert tone="warning">حركة هذا المخزن (بيع وشراء وتسويات) مجمّدة طوال نشاط جلسة الجرد هذه.</Alert>
            )}

            <Card title="سطور الجرد">
                <Table head={['الصنف', 'الرمز', 'الرصيد المتوقع', 'الكمية المعدودة', 'الفرق', 'تكلفة الوحدة']}>
                    {stockCount.lines.map((line) => {
                        const counted = form.data.lines[line.item_id];
                        const diff = counted === '' || counted === undefined ? null : Number(counted) - Number(line.expected_quantity);

                        return (
                            <tr key={line.id}>
                                <td className="px-3 py-2">{line.item?.name_ar ?? '—'}</td>
                                <td className="px-3 py-2 num text-slate-500">{line.item?.sku ?? '—'}</td>
                                <td className="px-3 py-2 num">{qty(line.expected_quantity)}</td>
                                <td className="px-3 py-2 num">
                                    {active ? (
                                        <NumberInput
                                            className="w-28"
                                            value={counted ?? ''}
                                            onChange={(e) => form.setData('lines', { ...form.data.lines, [line.item_id]: e.target.value })}
                                        />
                                    ) : (
                                        qty(line.counted_quantity)
                                    )}
                                </td>
                                <td className={`px-3 py-2 num ${diff && diff !== 0 ? (diff > 0 ? 'text-emerald-700' : 'text-rose-700') : ''}`}>
                                    {diff === null ? '—' : diff.toFixed(3)}
                                </td>
                                <td className="px-3 py-2 num">{qty(line.unit_cost_snapshot)}</td>
                            </tr>
                        );
                    })}
                </Table>
            </Card>
        </AppLayout>
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
