import { useForm } from '@inertiajs/react';
import { Alert, Badge, Button, Card, Field, Select, Table, TextInput } from '@/components/ui';

interface TaxCode {
    id: number;
    code: string;
    name: string;
    category: string;
    rate: string;
    is_active: boolean;
    valid_from: string;
}

const categoryLabels: Record<string, string> = {
    S: 'قياسي',
    Z: 'صفري',
    E: 'معفى',
    O: 'خارج النطاق',
};

export default function TaxCodesEditor({ taxCodes, vatRegistered }: { taxCodes: TaxCode[]; vatRegistered: boolean }) {
    const form = useForm({
        code: '',
        name: '',
        category: 'S',
        rate: '15',
        exemption_reason_code: '',
        exemption_reason: '',
        valid_from: new Date().toISOString().slice(0, 10),
        valid_to: '',
        is_active: true,
    });

    return (
        <Card title="المعالجات الضريبية">
            <div className="space-y-4">
                {!vatRegistered && (
                    <Alert tone="info">
                        المنشأة غير مسجّلة ضريبيًا حسب بيانات الإعداد، فلا حاجة لمعالجات ضريبية الآن. يمكن تعريفها لاحقًا عند التسجيل.
                    </Alert>
                )}

                <p className="text-sm text-slate-600">
                    النسبة تُعرَّف لكل معالجة، ويُربط الصنف أو الخدمة بالمعالجة المناسبة له. لا نسبة واحدة مفروضة على كل السلع والخدمات.
                </p>

                <Table head={['الكود', 'الاسم', 'التصنيف', 'النسبة %', 'سارية من', 'الحالة']} empty={taxCodes.length === 0}>
                    {taxCodes.map((t) => (
                        <tr key={t.id}>
                            <td className="px-3 py-2 num">{t.code}</td>
                            <td className="px-3 py-2">{t.name}</td>
                            <td className="px-3 py-2">{categoryLabels[t.category] ?? t.category}</td>
                            <td className="px-3 py-2 num">{Number(t.rate).toFixed(2)}</td>
                            <td className="px-3 py-2 num">{t.valid_from?.slice(0, 10)}</td>
                            <td className="px-3 py-2">
                                <Badge tone={t.is_active ? 'green' : 'slate'}>{t.is_active ? 'نشطة' : 'موقوفة'}</Badge>
                            </td>
                        </tr>
                    ))}
                </Table>

                <form
                    className="grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/tax-codes', { onSuccess: () => form.reset() });
                    }}
                >
                    <Field label="الكود" required error={form.errors.code}>
                        <TextInput dir="ltr" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="VAT15" />
                    </Field>
                    <Field label="الاسم" required error={form.errors.name}>
                        <TextInput value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="ضريبة 15%" />
                    </Field>
                    <Field label="التصنيف" required>
                        <Select
                            value={form.data.category}
                            onChange={(e) => {
                                const c = e.target.value;
                                form.setData('category', c);
                                if (c !== 'S') form.setData('rate', '0');
                            }}
                        >
                            {Object.entries(categoryLabels).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="النسبة %" required error={form.errors.rate}>
                        <TextInput dir="ltr" className="num" value={form.data.rate} onChange={(e) => form.setData('rate', e.target.value)} />
                    </Field>
                    <Field label="سارية من" required>
                        <TextInput type="date" value={form.data.valid_from} onChange={(e) => form.setData('valid_from', e.target.value)} />
                    </Field>
                    <Field label="سبب المعالجة" error={form.errors.exemption_reason} hint="مطلوب للصفري والمعفى وخارج النطاق.">
                        <TextInput value={form.data.exemption_reason} onChange={(e) => form.setData('exemption_reason', e.target.value)} />
                    </Field>
                    <div className="sm:col-span-3">
                        <Button type="submit" loading={form.processing} icon="plus">
                            إضافة معالجة ضريبية
                        </Button>
                    </div>
                </form>
            </div>
        </Card>
    );
}
