import { Link, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import Icon from '@/components/Icon';
import { Badge, Card, EmptyState, Money, Table } from '@/components/ui';
import { dateAr, einvoiceStatusLabels, methodLabels, money, qty } from '@/lib/format';
import type { PageProps } from '@/types';

interface DashboardProps extends PageProps {
    today: {
        date: string;
        net_sales: string;
        tax_total: string;
        gross_total: string;
        invoices: number;
        returns: number;
        collections_by_method: Record<string, string>;
    };
    alerts: {
        low_stock: { id: number; sku: string; name_ar: string; reorder_level: string; available: string }[];
        overdue_receivables: { id: number; document_number: string; due_date: string | null; party_name: string; outstanding: string }[];
        einvoice_problems: { id: number; status: string; reporting_due_at: string | null; document_number: string }[];
    };
    einvoice_summary: Record<string, number>;
    open_cash_session: { id: number; account: string; opened_at: string; opening_cash: string } | null;
}

export default function Dashboard() {
    const { today, alerts, einvoice_summary, open_cash_session } = usePage<DashboardProps>().props;

    const collections = Object.entries(today.collections_by_method ?? {});
    const totalCollected = collections.reduce((sum, [, v]) => sum + Number(v), 0);

    return (
        <AppLayout title="الرئيسية">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Stat label="صافي مبيعات اليوم (بدون ضريبة)" value={money(today.net_sales)} hint={`${today.invoices} فاتورة، ${today.returns} مرتجع`} icon="receipt" />
                <Stat label="ضريبة اليوم" value={money(today.tax_total)} hint="التزام وليس إيرادًا" icon="file" />
                <Stat label="إجمالي اليوم بالضريبة" value={money(today.gross_total)} icon="chart" />
                <Stat label="التحصيل الفعلي اليوم" value={money(String(totalCollected))} hint="دفعات مرحّلة، لا فواتير" icon="wallet" />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="التحصيل حسب الوسيلة">
                    {collections.length === 0 ? (
                        <EmptyState title="لا تحصيل اليوم" description="لم تُرحّل أي دفعة بتاريخ اليوم." />
                    ) : (
                        <Table head={['الوسيلة', 'المبلغ']}>
                            {collections.map(([method, amount]) => (
                                <tr key={method}>
                                    <td className="px-3 py-2">{methodLabels[method] ?? method}</td>
                                    <td className="px-3 py-2">
                                        <Money value={amount} />
                                    </td>
                                </tr>
                            ))}
                        </Table>
                    )}
                </Card>

                <Card
                    title="الصندوق"
                    action={
                        <Link href="/cash-sessions" className="text-sm font-semibold text-brand-700 hover:underline">
                            إدارة الصندوق
                        </Link>
                    }
                >
                    {open_cash_session ? (
                        <div className="space-y-2 text-sm">
                            <p className="font-semibold text-ink-900">{open_cash_session.account} — جلسة مفتوحة</p>
                            <p className="text-slate-600">
                                رصيد الافتتاح: <Money value={open_cash_session.opening_cash} /> ريال
                            </p>
                        </div>
                    ) : (
                        <EmptyState title="لا جلسة صندوق مفتوحة" description="افتح الصندوق قبل بدء البيع النقدي." />
                    )}
                </Card>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card title="نواقص المخزون">
                    <Table head={['الصنف', 'المتاح', 'حد الطلب']} empty={alerts.low_stock.length === 0}>
                        {alerts.low_stock.map((row) => (
                            <tr key={row.id}>
                                <td className="px-3 py-2">
                                    <span className="block font-medium">{row.name_ar}</span>
                                    <span className="num text-xs text-slate-500">{row.sku}</span>
                                </td>
                                <td className="px-3 py-2 num text-rose-700">{qty(row.available)}</td>
                                <td className="px-3 py-2 num text-slate-500">{qty(row.reorder_level)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>

                <Card title="مستحقات العملاء">
                    <Table head={['الفاتورة', 'العميل', 'المتبقي']} empty={alerts.overdue_receivables.length === 0}>
                        {alerts.overdue_receivables.map((row) => (
                            <tr key={row.id}>
                                <td className="px-3 py-2 num">{row.document_number}</td>
                                <td className="px-3 py-2">
                                    <span className="block">{row.party_name}</span>
                                    <span className="text-xs text-slate-500">استحقاق {dateAr(row.due_date)}</span>
                                </td>
                                <td className="px-3 py-2">
                                    <Money value={row.outstanding} />
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>

                <Card title="حالات الفوترة الإلكترونية">
                    <div className="mb-3 flex flex-wrap gap-2">
                        {Object.keys(einvoice_summary ?? {}).length === 0 ? (
                            <p className="text-sm text-slate-500">لا توجد مستندات إلكترونية بعد.</p>
                        ) : (
                            Object.entries(einvoice_summary).map(([status, count]) => (
                                <Badge
                                    key={status}
                                    tone={status === 'cleared' || status === 'reported' ? 'green' : status === 'rejected' ? 'rose' : 'amber'}
                                >
                                    {einvoiceStatusLabels[status] ?? status}: {count}
                                </Badge>
                            ))
                        )}
                    </div>

                    <Table head={['المستند', 'الحالة', 'المهلة']} empty={alerts.einvoice_problems.length === 0}>
                        {alerts.einvoice_problems.map((row) => (
                            <tr key={row.id}>
                                <td className="px-3 py-2 num">{row.document_number}</td>
                                <td className="px-3 py-2">{einvoiceStatusLabels[row.status] ?? row.status}</td>
                                <td className="px-3 py-2 text-xs text-slate-500">{dateAr(row.reporting_due_at)}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </div>
        </AppLayout>
    );
}

function Stat({ label, value, hint, icon }: { label: string; value: string; hint?: string; icon: string }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex items-start justify-between">
                <p className="text-sm text-slate-500">{label}</p>
                <span className="rounded-lg bg-brand-50 p-1.5 text-brand-700">
                    <Icon name={icon} className="size-4" />
                </span>
            </div>
            <p className="num mt-2 text-2xl font-bold text-ink-900">{value}</p>
            {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
        </div>
    );
}
