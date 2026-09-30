import { router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import Pagination from '@/components/Pagination';
import { Badge, Button, Card, Money, Select, Table, TextInput } from '@/components/ui';
import { qty } from '@/lib/format';
import type { Paginated, PageProps } from '@/types';

interface BalanceRow {
    id: number;
    sku: string;
    name_ar: string;
    quantity: string | null;
    inventory_value: string | null;
    average_cost: string | null;
    reserved: string | null;
    reorder_level: string;
    name: string | null;
}

interface Props extends PageProps {
    balances: Paginated<BalanceRow>;
    filters: { q?: string; low_only?: boolean };
    totals: { inventory_value: string; items_with_stock: number };
}

export default function Balances() {
    const { balances, filters, totals } = usePage<Props>().props;

    return (
        <AppLayout title="أرصدة المخزون">
            <div className="grid gap-4 sm:grid-cols-3">
                <Card>
                    <p className="text-sm text-slate-500">قيمة المخزون</p>
                    <p className="num text-2xl font-bold text-ink-900">
                        <Money value={totals.inventory_value} />
                    </p>
                    <p className="text-xs text-slate-500">مطابقة لحساب المخزون في الأستاذ</p>
                </Card>
                <Card>
                    <p className="text-sm text-slate-500">أصناف عليها رصيد</p>
                    <p className="num text-2xl font-bold text-ink-900">{totals.items_with_stock}</p>
                </Card>
                <Card>
                    <form
                        className="space-y-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const data = Object.fromEntries(new FormData(e.currentTarget as HTMLFormElement) as never);
                            router.get('/inventory/balances', data, { preserveState: true, replace: true });
                        }}
                    >
                        <TextInput name="q" placeholder="بحث بالاسم أو الرمز" defaultValue={filters.q ?? ''} />
                        <div className="flex gap-2">
                            <Select name="low_only" defaultValue={filters.low_only ? '1' : ''}>
                                <option value="">كل الأصناف</option>
                                <option value="1">النواقص فقط</option>
                            </Select>
                            <Button type="submit" variant="secondary" icon="search">
                                بحث
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>

            <Card title="الأرصدة بالوحدة الأساسية">
                <Table head={['الصنف', 'الوحدة الأساسية', 'الرصيد', 'محجوز', 'المتاح', 'متوسط التكلفة', 'قيمة المخزون', 'حد الطلب']} empty={balances.data.length === 0}>
                    {balances.data.map((row) => {
                        const available = Number(row.quantity ?? 0) - Number(row.reserved ?? 0);
                        const low = available <= Number(row.reorder_level);

                        return (
                            <tr key={row.id}>
                                <td className="px-3 py-2">
                                    <span className="block font-medium">{row.name_ar}</span>
                                    <span className="num text-xs text-slate-500">{row.sku}</span>
                                </td>
                                <td className="px-3 py-2">{row.name ?? '—'}</td>
                                <td className="px-3 py-2 num">{qty(row.quantity)}</td>
                                <td className="px-3 py-2 num">{qty(row.reserved)}</td>
                                <td className="px-3 py-2 num font-semibold">{qty(available)}</td>
                                <td className="px-3 py-2"><Money value={row.average_cost} /></td>
                                <td className="px-3 py-2"><Money value={row.inventory_value} /></td>
                                <td className="px-3 py-2">
                                    {low ? <Badge tone="rose">دون {qty(row.reorder_level)}</Badge> : <span className="num text-slate-500">{qty(row.reorder_level)}</span>}
                                </td>
                            </tr>
                        );
                    })}
                </Table>

                <Pagination links={balances.links} />
            </Card>
        </AppLayout>
    );
}
