<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Modules\Accounting\Models\Account;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Requests\ItemRequest;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Settings\Models\TaxCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ItemController
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): Response
    {
        $items = Item::query()
            ->with(['baseUnit:id,code,name', 'category:id,name', 'taxCode:id,code,name,rate', 'units.unit:id,code,name', 'units.barcodes'])
            ->when($request->string('q')->toString(), function ($q, $search) {
                $q->where(function ($sub) use ($search): void {
                    $sub->where('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhereIn('id', function ($barcode) use ($search): void {
                            $barcode->select('item_units.item_id')
                                ->from('item_units')
                                ->join('item_barcodes', 'item_barcodes.item_unit_id', '=', 'item_units.id')
                                ->where('item_barcodes.barcode', $search);
                        });
                });
            })
            ->when($request->string('type')->toString(), fn ($q, $type) => $q->where('item_type', $type))
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('is_active', $request->string('status')->toString() === 'active'))
            ->leftJoin('stock_balances', function ($join): void {
                $join->on('stock_balances.item_id', '=', 'items.id');
            })
            ->select('items.*', DB::raw('COALESCE(stock_balances.quantity, 0) AS stock_quantity'), DB::raw('COALESCE(stock_balances.average_cost, 0) AS average_cost'))
            ->orderBy('items.name_ar')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Catalog/Items', [
            'items' => $items,
            'filters' => $request->only('q', 'type', 'status'),
            'options' => $this->options(),
        ]);
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        $this->catalog->create($request->payload());

        return back()->with('success', 'تمت إضافة الصنف.');
    }

    public function update(ItemRequest $request, Item $item): RedirectResponse
    {
        $this->catalog->update($item, $request->payload());

        return back()->with('success', 'تم تحديث الصنف.');
    }

    public function deactivate(Item $item): RedirectResponse
    {
        $this->catalog->deactivate($item);

        return back()->with('success', 'تم إيقاف الصنف. المستندات السابقة لم تتغير.');
    }

    public function activate(Item $item): RedirectResponse
    {
        $item->update(['is_active' => true]);

        return back()->with('success', 'تم تفعيل الصنف.');
    }

    public function saveRecipe(Request $request, ItemUnit $itemUnit): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.material_item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.base_quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->catalog->saveRecipe($itemUnit, $data['lines']);

        return back()->with('success', 'تم حفظ وصفة الخامات. الاستهلاك التاريخي لم يتغير.');
    }

    private function options(): array
    {
        return [
            'units' => Unit::query()->orderBy('code')->get(['id', 'code', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'tax_codes' => TaxCode::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'category', 'rate']),
            'accounts' => Account::query()->where('allow_posting', true)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'account_type']),
            'materials' => Item::query()->where('item_type', 'stock')->where('is_active', true)
                ->orderBy('name_ar')->get(['id', 'sku', 'name_ar']),
        ];
    }
}
