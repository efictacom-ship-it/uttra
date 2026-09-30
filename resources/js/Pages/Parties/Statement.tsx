import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Button, Card, Field, Money, Table, TextInput } from '@/components/ui';
import { dateAr, docKindLabels } from '@/lib/format';
import type { PageProps } from '@/types';

interface StatementProps extends PageProps {
    party: { id: number; name: string; is_customer: boolean; is_supplier: boolean };
    statement: {
        from: string;
        to: string;
        opening_balance: string;
        closing_balance: string;
        lines: {
            entry_date: string;
            description: string;
            account_code: string;
            account_name: string;
            debit: string;
            credit: string;
            document_number: string | null;
            document_kind: string | null;
            payment_number: string | null;
            balance: string;
        }[];
    };
}

export default function Statement() {
    const { party, statement } = usePage<StatementProps>().props;
    const [from, setFrom] = useState(statement.from);
    const [to, setTo] = useState(statement.to);

    return (
        <AppLayout
            title={`كشف حساب: ${party.name}`}
            actions={
                <Button variant="secondary" icon="print" onClick={() => window.print()}>
                    طباعة
                </Button>
            }
        >
            <Card>
                <form
                    className="grid gap-3 sm:grid-cols-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(`/parties/${party.id}`, { from, to }, { preserveState: true });
                    }}
                >
                    <Field label="من">
                        <TextInput type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                    </Field>
                    <Field label="إلى">
                        <TextInput type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                    </Field>
                    <div className="flex items-end">
                        <Button type="submit" variant="secondary">عرض</Button>
                    </div>
                    <div className="flex items-end justify-end text-sm">
                        <p>
                            رصيد أول المدة: <Money value={statement.opening_balance} /> · رصيد آخر المدة:{' '}
                            <strong><Money value={statement.closing_balance} /></strong>
                        </p>
                    </div>
                </form>
            </Card>

            <Card title="الحركة">
                <Table head={['التاريخ', 'البيان', 'المرجع', 'الحساب', 'مدين', 'دائن', 'الرصيد']} empty={statement.lines.length === 0}>
                    {statement.lines.map((line, i) => (
                        <tr key={i}>
                            <td className="px-3 py-2 num">{dateAr(line.entry_date)}</td>
                            <td className="px-3 py-2">{line.description}</td>
                            <td className="px-3 py-2 num text-xs">
                                {line.document_number ? `${docKindLabels[line.document_kind ?? ''] ?? ''} ${line.document_number}` : line.payment_number ?? '—'}
                            </td>
                            <td className="px-3 py-2 text-xs">{line.account_code} — {line.account_name}</td>
                            <td className="px-3 py-2"><Money value={line.debit} /></td>
                            <td className="px-3 py-2"><Money value={line.credit} /></td>
                            <td className="px-3 py-2 font-semibold"><Money value={line.balance} /></td>
                        </tr>
                    ))}
                </Table>
            </Card>
        </AppLayout>
    );
}
