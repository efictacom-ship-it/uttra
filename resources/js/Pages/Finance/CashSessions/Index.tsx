import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import Pagination from '@/components/Pagination';
import { Alert, Badge, Button, Card, Field, Money, NumberInput, Select, Table, TextInput } from '@/components/ui';
import { dateTimeAr } from '@/lib/format';
import type { Paginated, PageProps } from '@/types';

interface SessionRow {
    id: number;
    opened_at: string;
    closed_at: string | null;
    opening_cash: string;
    expected_cash: string | null;
    counted_cash: string | null;
    difference_reason: string | null;
    financial_account: { id: number; name: string } | null;
}

interface IndexProps extends PageProps {
    sessions: Paginated<SessionRow>;
    financialAccounts: { id: number; name: string }[];
}

export default function CashSessionsIndex() {
    const { sessions, financialAccounts, errors } = usePage<IndexProps>().props;
    const [opening, setOpening] = useState(false);

    return (
        <AppLayout
            title="صناديق الكاشير"
            actions={
                <Button icon="plus" onClick={() => setOpening((v) => !v)}>
                    فتح صندوق جديد
                </Button>
            }
        >
            {(errors as Record<string, string>).message && <Alert>{(errors as Record<string, string>).message}</Alert>}

            {opening && <OpenForm accounts={financialAccounts} onClose={() => setOpening(false)} />}

            <Card title={`الصناديق (${sessions.total})`}>
                <Table
                    head={['الحساب', 'وقت الفتح', 'الافتتاحي', 'وقت الإغلاق', 'المتوقع', 'المعدود', 'الحالة', '']}
                    empty={sessions.data.length === 0}
                >
                    {sessions.data.map((s) => (
                        <tr key={s.id}>
                            <td className="px-3 py-2">{s.financial_account?.name ?? '—'}</td>
                            <td className="px-3 py-2 num">{dateTimeAr(s.opened_at)}</td>
                            <td className="px-3 py-2"><Money value={s.opening_cash} /></td>
                            <td className="px-3 py-2 num">{s.closed_at ? dateTimeAr(s.closed_at) : '—'}</td>
                            <td className="px-3 py-2">{s.expected_cash !== null ? <Money value={s.expected_cash} /> : '—'}</td>
                            <td className="px-3 py-2">{s.counted_cash !== null ? <Money value={s.counted_cash} /> : '—'}</td>
                            <td className="px-3 py-2">
                                <Badge tone={s.closed_at ? 'slate' : 'green'}>{s.closed_at ? 'مغلق' : 'مفتوح'}</Badge>
                            </td>
                            <td className="px-3 py-2">{!s.closed_at && <CloseForm sessionId={s.id} />}</td>
                        </tr>
                    ))}
                </Table>
                <Pagination links={sessions.links} />
            </Card>
        </AppLayout>
    );
}

function OpenForm({ accounts, onClose }: { accounts: { id: number; name: string }[]; onClose: () => void }) {
    const form = useForm({ financial_account_id: accounts[0]?.id ?? ('' as number | ''), opening_cash: '0' });

    return (
        <Card title="فتح صندوق" className="mb-4" action={<Button variant="ghost" size="sm" onClick={onClose}>إغلاق</Button>}>
            <form
                className="grid gap-4 sm:grid-cols-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/cash-sessions/open', { onSuccess: () => onClose() });
                }}
            >
                <Field label="الحساب المالي" required error={form.errors.financial_account_id}>
                    <Select value={String(form.data.financial_account_id)} onChange={(e) => form.setData('financial_account_id', Number(e.target.value))}>
                        {accounts.map((a) => (
                            <option key={a.id} value={a.id}>{a.name}</option>
                        ))}
                    </Select>
                </Field>
                <Field label="الرصيد الافتتاحي" required>
                    <NumberInput value={form.data.opening_cash} onChange={(e) => form.setData('opening_cash', e.target.value)} />
                </Field>
                <div className="flex items-end">
                    <Button type="submit" loading={form.processing}>فتح الصندوق</Button>
                </div>
            </form>
        </Card>
    );
}

function CloseForm({ sessionId }: { sessionId: number }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ counted_cash: '', reason: '' });

    if (!open) {
        return (
            <Button size="sm" variant="secondary" onClick={() => setOpen(true)}>
                إغلاق الصندوق
            </Button>
        );
    }

    return (
        <div className="flex flex-wrap items-end gap-2">
            <TextInput
                className="w-28 num"
                placeholder="النقدية المعدودة"
                dir="ltr"
                value={form.data.counted_cash}
                onChange={(e) => form.setData('counted_cash', e.target.value)}
            />
            <TextInput
                className="w-36"
                placeholder="سبب الفرق (إن وجد)"
                value={form.data.reason}
                onChange={(e) => form.setData('reason', e.target.value)}
            />
            <Button
                size="sm"
                loading={form.processing}
                onClick={() => {
                    form.post(`/cash-sessions/${sessionId}/close`, { onSuccess: () => setOpen(false), preserveScroll: true });
                }}
            >
                تأكيد
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>إلغاء</Button>
        </div>
    );
}
