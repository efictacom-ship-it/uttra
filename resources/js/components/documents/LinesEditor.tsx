import { Button, Field, Money, NumberInput, Select, TextInput } from '@/components/ui';

export interface ItemOption {
    id: number;
    sku: string;
    name_ar: string;
    item_type?: string;
    units: { id: number; unit_id: number; to_base_factor: string; sale_price: string; price_includes_tax: boolean; unit?: { name: string } }[];
}

export interface LineDraft {
    item_id: number | '';
    item_unit_id: number | '';
    description: string;
    quantity: string;
    unit_price: string;
    discount_amount: string;
    price_includes_tax: boolean;
}

export function emptyLine(): LineDraft {
    return { item_id: '', item_unit_id: '', description: '', quantity: '1', unit_price: '0', discount_amount: '0', price_includes_tax: false };
}

/** محرر سطور مشترك للشراء والمرتجعات. الإجماليات المعروضة تقديرية؛ السيرفر هو المرجع. */
export default function LinesEditor({
    lines,
    items,
    onChange,
    priceLabel = 'سعر الوحدة',
}: {
    lines: LineDraft[];
    items: ItemOption[];
    onChange: (lines: LineDraft[]) => void;
    priceLabel?: string;
}) {
    const patch = (index: number, changes: Partial<LineDraft>) => {
        const next = [...lines];
        next[index] = { ...next[index], ...changes };
        onChange(next);
    };

    const pickItem = (index: number, itemId: number) => {
        const item = items.find((i) => i.id === itemId);
        const unit = item?.units[0];

        patch(index, {
            item_id: itemId,
            item_unit_id: unit?.id ?? '',
            description: item?.name_ar ?? '',
            unit_price: unit?.sale_price ?? '0',
            price_includes_tax: unit?.price_includes_tax ?? false,
        });
    };

    const estimate = lines.reduce((sum, line) => sum + Number(line.quantity || 0) * Number(line.unit_price || 0) - Number(line.discount_amount || 0), 0);

    return (
        <div className="space-y-3">
            {lines.map((line, index) => {
                const item = items.find((i) => i.id === Number(line.item_id));

                return (
                    <div key={index} className="grid items-end gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-7">
                        <Field label="الصنف">
                            <Select value={String(line.item_id)} onChange={(e) => pickItem(index, Number(e.target.value))}>
                                <option value="">اختر صنفًا</option>
                                {items.map((i) => (
                                    <option key={i.id} value={i.id}>
                                        {i.name_ar} ({i.sku})
                                    </option>
                                ))}
                            </Select>
                        </Field>

                        <Field label="الوحدة">
                            <Select
                                value={String(line.item_unit_id)}
                                onChange={(e) => {
                                    const unit = item?.units.find((u) => u.id === Number(e.target.value));
                                    patch(index, { item_unit_id: Number(e.target.value), unit_price: unit?.sale_price ?? line.unit_price });
                                }}
                            >
                                <option value="">—</option>
                                {item?.units.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.unit?.name} (×{Number(u.to_base_factor)})
                                    </option>
                                ))}
                            </Select>
                        </Field>

                        <Field label="الوصف">
                            <TextInput value={line.description} onChange={(e) => patch(index, { description: e.target.value })} />
                        </Field>

                        <Field label="الكمية">
                            <NumberInput value={line.quantity} onChange={(e) => patch(index, { quantity: e.target.value })} />
                        </Field>

                        <Field label={priceLabel}>
                            <NumberInput value={line.unit_price} onChange={(e) => patch(index, { unit_price: e.target.value })} />
                        </Field>

                        <Field label="خصم السطر">
                            <NumberInput value={line.discount_amount} onChange={(e) => patch(index, { discount_amount: e.target.value })} />
                        </Field>

                        <div className="flex items-center gap-2">
                            <Field label="شامل الضريبة؟">
                                <Select
                                    value={line.price_includes_tax ? '1' : '0'}
                                    onChange={(e) => patch(index, { price_includes_tax: e.target.value === '1' })}
                                >
                                    <option value="0">غير شامل</option>
                                    <option value="1">شامل</option>
                                </Select>
                            </Field>
                            {lines.length > 1 && (
                                <Button type="button" variant="ghost" size="sm" icon="trash" onClick={() => onChange(lines.filter((_, i) => i !== index))}>
                                    <span className="sr-only">حذف السطر</span>
                                </Button>
                            )}
                        </div>
                    </div>
                );
            })}

            <div className="flex items-center justify-between">
                <Button type="button" variant="secondary" size="sm" icon="plus" onClick={() => onChange([...lines, emptyLine()])}>
                    إضافة سطر
                </Button>
                <p className="text-sm text-slate-500">
                    تقدير قبل الضريبة: <Money value={estimate} /> ريال — الحساب النهائي يتم على السيرفر.
                </p>
            </div>
        </div>
    );
}
