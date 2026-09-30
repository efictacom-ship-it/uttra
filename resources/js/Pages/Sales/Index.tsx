import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import Pagination from '@/components/Pagination';
import { Badge, Button, Card, Money, Select, Table, TextInput } from '@/components/ui';
import { dateAr, statusLabels } from '@/lib/format';
import type { Paginated, PageProps } from '@/types';

interface SaleDoc {
    id: number;
    document_number: string | null;
    status: string;
    business_date: string;
    net_total: string;
    tax_total: string;
    grand_total: string;
    party: { id: number; name: string } | null;
}

interface SalesIndexProps extends PageProps {
    documents: Paginated<SaleDoc>;
    filters: { q?: string; status?: string; from?: string; to?: string };
}

export default function SalesIndex() {
    const { documents, filters } = usePage<SalesIndexProps>().props;

    return (
        <AppLayout
            title="فواتير البيع"
            actions={
                <Link href="/pos" className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    فتح الكاشير
                </Link>
            }
        >
            <Card>
                <form
                    className="grid gap-3 sm:grid-cols-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const data = Object.fromEntries(new FormData(e.currentTarget as HTMLFormElement) as never);
                        router.get('/sales', data, { preserveState: true, replace: true });
                    }}
                >
                    <TextInput name="q" placeholder="رقم الفاتورة" defaultValue={filters.q ?? ''} />
                    <Select name="status" defaultValue={filters.status ?? ''}>
                        <option value="">كل الحالات</option>
                        <option value="draft">مسودة (سلة معلّقة)</option>
                        <option value="posted">مرحّلة</option>
                    </Select>
                    <TextInput name="from" type="date" defaultValue={filters.from ?? ''} />
                    <TextInput name="to" type="date" defaultValue={filters.to ?? ''} />
                    <Button type="submit" variant="secondary" icon="search">
                        بحث
                    </Button>
                </form>
            </Card>

            <Card title={`فواتير البيع (${documents.total})`}>
                <Table head={['الرقم', 'العميل', 'التاريخ', 'الصافي', 'الضريبة', 'الإجمالي', 'الحالة', '']} empty={documents.data.length === 0}>
                    {documents.data.map((doc) => (
                        <tr key={doc.id}>
                            <td className="px-3 py-2 num">
                                <Link href={`/sales/${doc.id}`} className="text-brand-700 hover:underline">
                                    {doc.document_number ?? `مسودة #${doc.id}`}
                                </Link>
                            </td>
                            <td className="px-3 py-2">{doc.party?.name ?? 'نقدي'}</td>
                            <td className="px-3 py-2 num">{dateAr(doc.business_date)}</td>
                            <td className="px-3 py-2"><Money value={doc.net_total} /></td>
                            <td className="px-3 py-2"><Money value={doc.tax_total} /></td>
                            <td className="px-3 py-2 font-semibold"><Money value={doc.grand_total} /></td>
                            <td className="px-3 py-2">
                                <Badge tone={doc.status === 'posted' ? 'green' : doc.status === 'draft' ? 'amber' : 'slate'}>
                                    {statusLabels[doc.status]}
                                </Badge>
                            </td>
                            <td className="px-3 py-2">
                                {doc.status === 'draft' ? (
                                    <Link href={`/pos?draft=${doc.id}`} className="text-brand-700 hover:underline">
                                        استئناف في الكاشير
                                    </Link>
                                ) : (
                                    <Link href={`/sales/${doc.id}`} className="text-brand-700 hover:underline">
                                        عرض/طباعة
                                    </Link>
                                )}
                            </td>
                        </tr>
                    ))}
                </Table>

                <Pagination links={documents.links} />
            </Card>
        </AppLayout>
    );
}
