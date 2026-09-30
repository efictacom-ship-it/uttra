import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Alert, Button, Card, Field, Select, TextInput } from '@/components/ui';
import type { ItemRecord } from './ItemForm';

interface Line {
    material_item_id: number | '';
    base_quantity: string;
}

/**
 * وصفة الخامات لكل وحدة خدمة (مثال: وجه مطبوع = 0.5 ورقة إن كان الطباعة وجهين).
 * تغيير الوصفة لا يغيّر الاستهلاك التاريخي المسجّل في حركات المخزون.
 */
export default function RecipeEditor({
    item,
    materials,
    onClose,
}: {
    item: ItemRecord;
    materials: { id: number; sku: string; name_ar: string }[];
    onClose: () => void;
}) {
    const [unitId, setUnitId] = useState<number>(item.units?.[0]?.id ?? 0);
    const currentUnit = item.units?.find((u) => u.id === unitId);

    const form = useForm<{ lines: Line[] }>({
        lines: [{ material_item_id: '', base_quantity: '1' }],
    });

    return (
        <Card title={`خامات الخدمة: ${item.name_ar}`} action={<Button variant="ghost" onClick={onClose}>إغلاق</Button>}>
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/item-units/${unitId}/recipe`, { preserveScroll: true, onSuccess: () => onClose() });
                }}
            >
                <Alert tone="info">
                    الكميات بالوحدة الأساسية للخامة، لكل وحدة خدمة واحدة. فرّق بين الوجه المطبوع وورقة الورق: وجهان على ورقة واحدة يعني 0.5
                    ورقة لكل وجه.
                </Alert>

                <Field label="وحدة الخدمة" hint="الوصفة تُعرَّف لكل وحدة بيع على حدة.">
                    <Select value={String(unitId)} onChange={(e) => setUnitId(Number(e.target.value))}>
                        {item.units?.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.unit?.name}
                            </option>
                        ))}
                    </Select>
                </Field>

                {currentUnit && (
                    <p className="text-xs text-slate-500">
                        سعر الوحدة الحالي: <span className="num">{Number(currentUnit.sale_price).toFixed(2)}</span> ريال
                    </p>
                )}

                {form.data.lines.map((line, index) => (
                    <div key={index} className="grid items-end gap-3 sm:grid-cols-3">
                        <Field label="الخامة" required>
                            <Select
                                value={String(line.material_item_id)}
                                onChange={(e) => {
                                    const lines = [...form.data.lines];
                                    lines[index] = { ...lines[index], material_item_id: Number(e.target.value) };
                                    form.setData('lines', lines);
                                }}
                            >
                                <option value="">اختر خامة مخزنية</option>
                                {materials.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.name_ar} ({m.sku})
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="الكمية لكل وحدة خدمة" required>
                            <TextInput
                                dir="ltr"
                                className="num"
                                value={line.base_quantity}
                                onChange={(e) => {
                                    const lines = [...form.data.lines];
                                    lines[index] = { ...lines[index], base_quantity: e.target.value };
                                    form.setData('lines', lines);
                                }}
                            />
                        </Field>
                        <div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                onClick={() => form.setData('lines', form.data.lines.filter((_, i) => i !== index))}
                            >
                                حذف
                            </Button>
                        </div>
                    </div>
                ))}

                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        icon="plus"
                        onClick={() => form.setData('lines', [...form.data.lines, { material_item_id: '', base_quantity: '1' }])}
                    >
                        إضافة خامة
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        حفظ الوصفة
                    </Button>
                </div>
            </form>
        </Card>
    );
}
