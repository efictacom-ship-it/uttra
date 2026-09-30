import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import Pagination from '@/components/Pagination';
import ItemForm, { type ItemRecord, type CatalogOptions } from '@/components/catalog/ItemForm';
import RecipeEditor from '@/components/catalog/RecipeEditor';
import { Badge, Button, Card, Money, Select, Table, TextInput } from '@/components/ui';
import { qty } from '@/lib/format';
import type { Paginated, PageProps } from '@/types';

interface ItemsProps extends PageProps {
    items: Paginated<ItemRecord & { stock_quantity: string; average_cost: string }>;
    filters: { q?: string; type?: string; status?: string };
    options: CatalogOptions;
}

export default function Items() {
    const { items, filters, options } = usePage<ItemsProps>().props;
    const [editing, setEditing] = useState<ItemRecord | null>(null);
    const [creating, setCreating] = useState(false);
    const [recipeFor, setRecipeFor] = useState<ItemRecord | null>(null);
    const [q, setQ] = useState(filters.q ?? '');

    const applyFilters = (patch: Record<string, string>) => {
        router.get('/items', { ...filters, ...patch }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout
            title="الأصناف والخدمات"
            actions={
                <Button icon="plus" onClick={() => { setCreating(true); setEditing(null); }}>
                    صنف أو خدمة جديدة
                </Button>
            }
        >
            <Card>
                <form
                    className="grid gap-3 sm:grid-cols-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        applyFilters({ q });
                    }}
                >
                    <TextInput placeholder="بحث بالاسم أو الرمز أو الباركود" value={q} onChange={(e) => setQ(e.target.value)} />
                    <Select value={filters.type ?? ''} onChange={(e) => applyFilters({ type: e.target.value })}>
                        <option value="">كل الأنواع</option>
                        <option value="stock">سلعة مخزنية</option>
                        <option value="service">خدمة</option>
                    </Select>
                    <Select value={filters.status ?? ''} onChange={(e) => applyFilters({ status: e.target.value })}>
                        <option value="">الكل</option>
                        <option value="active">نشط</option>
                        <option value="inactive">موقوف</option>
                    </Select>
                    <Button type="submit" variant="secondary" icon="search">
                        بحث
                    </Button>
                </form>
            </Card>

            {(creating || editing) && (
                <ItemForm
                    item={editing}
                    options={options}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            )}

            {recipeFor && <RecipeEditor item={recipeFor} materials={options.materials} onClose={() => setRecipeFor(null)} />}

            <Card title={`الأصناف (${items.total})`}>
                <Table head={['الصنف', 'النوع', 'الوحدات والأسعار', 'الرصيد', 'متوسط التكلفة', 'الضريبة', 'الحالة', '']} empty={items.data.length === 0}>
                    {items.data.map((item) => (
                        <tr key={item.id} className="align-top">
                            <td className="px-3 py-2">
                                <span className="block font-medium text-ink-900">{item.name_ar}</span>
                                <span className="num text-xs text-slate-500">{item.sku}</span>
                            </td>
                            <td className="px-3 py-2">{item.item_type === 'stock' ? 'سلعة' : 'خدمة'}</td>
                            <td className="px-3 py-2">
                                <ul className="space-y-0.5 text-xs">
                                    {item.units?.map((u) => (
                                        <li key={u.id} className="flex flex-wrap items-center gap-1.5">
                                            <span className="font-medium">{u.unit?.name}</span>
                                            <span className="num text-slate-500">×{qty(u.to_base_factor)}</span>
                                            <Money value={u.sale_price} />
                                            <span className="text-slate-400">{u.price_includes_tax ? 'شامل' : 'غير شامل'}</span>
                                            {u.barcodes?.map((b) => (
                                                <span key={b.id} className="num rounded bg-slate-100 px-1.5">{b.barcode}</span>
                                            ))}
                                        </li>
                                    ))}
                                </ul>
                            </td>
                            <td className="px-3 py-2 num">{item.item_type === 'stock' ? qty(item.stock_quantity) : '—'}</td>
                            <td className="px-3 py-2">{item.item_type === 'stock' ? <Money value={item.average_cost} /> : '—'}</td>
                            <td className="px-3 py-2 text-xs">{item.tax_code ? `${item.tax_code.name}` : 'غير محدد'}</td>
                            <td className="px-3 py-2">
                                <Badge tone={item.is_active ? 'green' : 'slate'}>{item.is_active ? 'نشط' : 'موقوف'}</Badge>
                            </td>
                            <td className="px-3 py-2">
                                <div className="flex flex-wrap gap-1">
                                    <Button size="sm" variant="secondary" onClick={() => { setEditing(item); setCreating(false); }}>
                                        تعديل
                                    </Button>
                                    {item.item_type === 'service' && (
                                        <Button size="sm" variant="ghost" onClick={() => setRecipeFor(item)}>
                                            الخامات
                                        </Button>
                                    )}
                                    {item.is_active ? (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                if (confirm('إيقاف الصنف يمنع استخدامه في مستندات جديدة فقط. متابعة؟')) {
                                                    router.post(`/items/${item.id}/deactivate`);
                                                }
                                            }}
                                        >
                                            إيقاف
                                        </Button>
                                    ) : (
                                        <Button size="sm" variant="ghost" onClick={() => router.post(`/items/${item.id}/activate`)}>
                                            تفعيل
                                        </Button>
                                    )}
                                </div>
                            </td>
                        </tr>
                    ))}
                </Table>

                <Pagination links={items.links} />
            </Card>
        </AppLayout>
    );
}
