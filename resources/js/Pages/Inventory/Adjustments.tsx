import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import Pagination from '@/components/Pagination';
import { Alert, Badge, Button, Card, Field, NumberInput, Select, Table, TextArea, TextInput } from '@/components/ui';
import { dateAr, qty } from '@/lib/format';
import type { Paginated, PageProps } from '@/types';

interface ItemUnitOption {
    id: number;
    unit_id: number;
    unit?: { id: number; code: string; name: string };
}

interface ItemOption {
    id: number;
    sku: string;
    name_ar: string;
    units: ItemUnitOption[];
}

interface WarehouseOption {
    id: number;
    name: string;
}

interface DocLine {
    id: number;
    item_id: number;
    quantity: string;
    stock_direction: 'in' | 'out';
    description: string;
    item?: { sku: string; name_ar: string };
}

interface AdjustmentDoc {
    id: number;
    document_number: string | null;
    kind: string;
    business_date: string;
    status: string;
    lines: DocLine[];
}

interface Props extends PageProps {
    documents: Paginated<AdjustmentDoc>;
    items: ItemOption[];
    warehouses: WarehouseOption[];
}

const kindLabelsAr: Record<string, string> = {
    stock_opening: 'رصيد افتتاحي',
    stock_adjustment: 'تسوية / تلف',
    material_usage: 'استهلاك خامات',
};

interface OpeningLine {
    item_id: number | '';
    item_unit_id: number | '';
    quantity: string;
    unit_cost: string;
    description: string;
}

function emptyOpeningLine(): OpeningLine {
    return { item_id: '', item_unit_id: '', quantity: '', unit_cost: '0', description: '' };
}

interface AdjustmentLine {
    item_id: number | '';
    item_unit_id: number | '';
    quantity: string;
    stock_direction: 'in' | 'out';
    unit_cost: string;
    description: string;
}

function emptyAdjustmentLine(): AdjustmentLine {
    return { item_id: '', item_unit_id: '', quantity: '', stock_direction: 'out', unit_cost: '', description: '' };
}

export default function Adjustments() {
    const { documents, items, warehouses, errors } = usePage<Props>().props;
    const [openingOpen, setOpeningOpen] = useState(false);
    const [adjustmentOpen, setAdjustmentOpen] = useState(false);

    return (
        <AppLayout
            title="التلف والتسويات"
            actions={
                <div className="flex gap-2">
                    <Button
                        variant="secondary"
                        icon="plus"
                        onClick={() => {
                            setAdjustmentOpen(false);
                            setOpeningOpen((v) => !v);
                        }}
                    >
                        رصيد افتتاحي
                    </Button>
                    <Button
                        icon="alert"
                        onClick={() => {
                            setOpeningOpen(false);
                            setAdjustmentOpen((v) => !v);
                        }}
                    >
                        تلف / تسوية
                    </Button>
                </div>
            }
        >
            {errors.message && <Alert>{errors.message}</Alert>}

            {openingOpen && <OpeningForm items={items} warehouses={warehouses} onClose={() => setOpeningOpen(false)} />}
            {adjustmentOpen && <AdjustmentForm items={items} warehouses={warehouses} onClose={() => setAdjustmentOpen(false)} />}

            <Card title={`المستندات (${documents.total})`}>
                <Table head={['الرقم', 'النوع', 'التاريخ', 'الحالة', 'السطور']} empty={documents.data.length === 0}>
                    {documents.data.map((doc) => (
                        <tr key={doc.id}>
                            <td className="px-3 py-2 num">{doc.document_number ?? `#${doc.id}`}</td>
                            <td className="px-3 py-2">{kindLabelsAr[doc.kind] ?? doc.kind}</td>
                            <td className="px-3 py-2 num">{dateAr(doc.business_date)}</td>
                            <td className="px-3 py-2">
                                <Badge tone={doc.status === 'posted' ? 'green' : 'slate'}>{doc.status === 'posted' ? 'مرحّل' : doc.status}</Badge>
                            </td>
                            <td className="px-3 py-2 text-sm text-slate-600">
                                {doc.lines.map((l) => `${l.item?.name_ar ?? l.item_id} (${qty(l.quantity)})`).join('، ')}
                            </td>
                        </tr>
                    ))}
                </Table>
                <Pagination links={documents.links} />
            </Card>
        </AppLayout>
    );
}

function OpeningForm({ items, warehouses, onClose }: { items: ItemOption[]; warehouses: WarehouseOption[]; onClose: () => void }) {
    const form = useForm({
        warehouse_id: warehouses[0]?.id ?? '',
        business_date: new Date().toISOString().slice(0, 10),
        notes: '',
        lines: [emptyOpeningLine()] as OpeningLine[],
    });

    const patchLine = (index: number, changes: Partial<OpeningLine>) => {
        const lines = [...form.data.lines];
        lines[index] = { ...lines[index], ...changes };
        form.setData('lines', lines);
    };

    const pickItem = (index: number, itemId: number) => {
        const item = items.find((i) => i.id === itemId);
        patchLine(index, { item_id: itemId, item_unit_id: item?.units[0]?.id ?? '', description: item ? `رصيد افتتاحي - ${item.name_ar}` : '' });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/inventory/opening', { onSuccess: () => onClose(), preserveScroll: true });
    };

    return (
        <Card title="إضافة رصيد افتتاحي" action={<Button variant="ghost" onClick={onClose}>إغلاق</Button>}>
            <form className="space-y-4" onSubmit={submit}>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="المخزن" required error={form.errors.warehouse_id}>
                        <Select value={String(form.data.warehouse_id)} onChange={(e) => form.setData('warehouse_id', Number(e.target.value))}>
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>{w.name}</option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="التاريخ" required error={form.errors.business_date}>
                        <TextInput type="date" value={form.data.business_date} onChange={(e) => form.setData('business_date', e.target.value)} />
                    </Field>
                    <Field label="ملاحظات">
                        <TextInput value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </Field>
                </div>

                <div className="space-y-3">
                    {form.data.lines.map((line, index) => {
                        const item = items.find((i) => i.id === Number(line.item_id));

                        return (
                            <div key={index} className="grid items-end gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-6">
                                <Field label="الصنف">
                                    <Select value={String(line.item_id)} onChange={(e) => pickItem(index, Number(e.target.value))}>
                                        <option value="">اختر صنفًا</option>
                                        {items.map((i) => (
                                            <option key={i.id} value={i.id}>{i.name_ar} ({i.sku})</option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="الوحدة">
                                    <Select value={String(line.item_unit_id)} onChange={(e) => patchLine(index, { item_unit_id: Number(e.target.value) })}>
                                        <option value="">—</option>
                                        {item?.units.map((u) => (
                                            <option key={u.id} value={u.id}>{u.unit?.name}</option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="الكمية">
                                    <NumberInput value={line.quantity} onChange={(e) => patchLine(index, { quantity: e.target.value })} />
                                </Field>
                                <Field label="تكلفة الوحدة">
                                    <NumberInput value={line.unit_cost} onChange={(e) => patchLine(index, { unit_cost: e.target.value })} />
                                </Field>
                                <Field label="الوصف">
                                    <TextInput value={line.description} onChange={(e) => patchLine(index, { description: e.target.value })} />
                                </Field>
                                <div>
                                    {form.data.lines.length > 1 && (
                                        <Button type="button" variant="ghost" size="sm" icon="trash" onClick={() => form.setData('lines', form.data.lines.filter((_, i) => i !== index))}>
                                            حذف
                                        </Button>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    <Button type="button" variant="secondary" size="sm" icon="plus" onClick={() => form.setData('lines', [...form.data.lines, emptyOpeningLine()])}>
                        إضافة سطر
                    </Button>
                </div>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>ترحيل الرصيد الافتتاحي</Button>
                </div>
            </form>
        </Card>
    );
}

function AdjustmentForm({ items, warehouses, onClose }: { items: ItemOption[]; warehouses: WarehouseOption[]; onClose: () => void }) {
    const form = useForm({
        warehouse_id: warehouses[0]?.id ?? '',
        business_date: new Date().toISOString().slice(0, 10),
        reason: '',
        lines: [emptyAdjustmentLine()] as AdjustmentLine[],
    });

    const patchLine = (index: number, changes: Partial<AdjustmentLine>) => {
        const lines = [...form.data.lines];
        lines[index] = { ...lines[index], ...changes };
        form.setData('lines', lines);
    };

    const pickItem = (index: number, itemId: number) => {
        const item = items.find((i) => i.id === itemId);
        patchLine(index, { item_id: itemId, item_unit_id: item?.units[0]?.id ?? '', description: item ? item.name_ar : '' });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            lines: data.lines.map((line) => ({ ...line, unit_cost: line.unit_cost === '' ? null : line.unit_cost })),
        }));
        form.post('/inventory/adjustments', { onSuccess: () => onClose(), preserveScroll: true });
    };

    return (
        <Card title="تسوية / تلف وفقد" action={<Button variant="ghost" onClick={onClose}>إغلاق</Button>}>
            <form className="space-y-4" onSubmit={submit}>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="المخزن" required error={form.errors.warehouse_id}>
                        <Select value={String(form.data.warehouse_id)} onChange={(e) => form.setData('warehouse_id', Number(e.target.value))}>
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>{w.name}</option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="التاريخ" required error={form.errors.business_date}>
                        <TextInput type="date" value={form.data.business_date} onChange={(e) => form.setData('business_date', e.target.value)} />
                    </Field>
                    <Field label="السبب" required error={form.errors.reason}>
                        <TextArea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>
                </div>

                <div className="space-y-3">
                    {form.data.lines.map((line, index) => {
                        const item = items.find((i) => i.id === Number(line.item_id));

                        return (
                            <div key={index} className="grid items-end gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-7">
                                <Field label="الصنف">
                                    <Select value={String(line.item_id)} onChange={(e) => pickItem(index, Number(e.target.value))}>
                                        <option value="">اختر صنفًا</option>
                                        {items.map((i) => (
                                            <option key={i.id} value={i.id}>{i.name_ar} ({i.sku})</option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="الوحدة">
                                    <Select value={String(line.item_unit_id)} onChange={(e) => patchLine(index, { item_unit_id: Number(e.target.value) })}>
                                        <option value="">—</option>
                                        {item?.units.map((u) => (
                                            <option key={u.id} value={u.id}>{u.unit?.name}</option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="الاتجاه">
                                    <Select value={line.stock_direction} onChange={(e) => patchLine(index, { stock_direction: e.target.value as 'in' | 'out' })}>
                                        <option value="out">نقص (تلف/فقد)</option>
                                        <option value="in">زيادة</option>
                                    </Select>
                                </Field>
                                <Field label="الكمية">
                                    <NumberInput value={line.quantity} onChange={(e) => patchLine(index, { quantity: e.target.value })} />
                                </Field>
                                <Field label="تكلفة الوحدة" hint="اتركها فارغة لاستخدام متوسط التكلفة الحالي">
                                    <NumberInput value={line.unit_cost} onChange={(e) => patchLine(index, { unit_cost: e.target.value })} />
                                </Field>
                                <Field label="الوصف">
                                    <TextInput value={line.description} onChange={(e) => patchLine(index, { description: e.target.value })} />
                                </Field>
                                <div>
                                    {form.data.lines.length > 1 && (
                                        <Button type="button" variant="ghost" size="sm" icon="trash" onClick={() => form.setData('lines', form.data.lines.filter((_, i) => i !== index))}>
                                            حذف
                                        </Button>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    <Button type="button" variant="secondary" size="sm" icon="plus" onClick={() => form.setData('lines', [...form.data.lines, emptyAdjustmentLine()])}>
                        إضافة سطر
                    </Button>
                </div>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>ترحيل التسوية</Button>
                </div>
            </form>
        </Card>
    );
}
