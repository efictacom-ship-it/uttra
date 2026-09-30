<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockDocumentService;
use App\Modules\Inventory\Services\StockPostingService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Services\DocumentDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController
{
    public function __construct(
        private readonly DocumentDraftService $drafts,
        private readonly StockDocumentService $stockDocuments,
        private readonly StockPostingService $stock,
    ) {}

    public function balances(Request $request): Response
    {
        $balances = DB::table('items')
            ->leftJoin('stock_balances', 'stock_balances.item_id', '=', 'items.id')
            ->leftJoin('units', 'units.id', '=', 'items.base_unit_id')
            ->leftJoin(DB::raw("(SELECT item_id, SUM(quantity) AS reserved FROM stock_reservations WHERE status = 'active' GROUP BY item_id) r"), 'r.item_id', '=', 'items.id')
            ->where('items.item_type', 'stock')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('items.name_ar', 'like', "%{$s}%")->orWhere('items.sku', 'like', "%{$s}%")))
            ->when($request->boolean('low_only'), fn ($q) => $q->whereRaw('COALESCE(stock_balances.quantity,0) - COALESCE(r.reserved,0) <= items.reorder_level'))
            ->orderBy('items.name_ar')
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($row) => (array) $row);

        return Inertia::render('Inventory/Balances', [
            'balances' => $balances,
            'filters' => $request->only('q', 'low_only'),
            'totals' => [
                'inventory_value' => (string) (DB::table('stock_balances')->sum('inventory_value') ?? '0'),
                'items_with_stock' => DB::table('stock_balances')->where('quantity', '>', 0)->count(),
            ],
        ]);
    }

    public function movements(Request $request): Response
    {
        $movements = DB::table('stock_movements')
            ->join('items', 'items.id', '=', 'stock_movements.item_id')
            ->leftJoin('documents', 'documents.id', '=', 'stock_movements.document_id')
            ->when($request->integer('item_id'), fn ($q, $id) => $q->where('stock_movements.item_id', $id))
            ->when($request->string('from')->toString(), fn ($q, $d) => $q->whereDate('stock_movements.occurred_at', '>=', $d))
            ->when($request->string('to')->toString(), fn ($q, $d) => $q->whereDate('stock_movements.occurred_at', '<=', $d))
            ->orderBy('stock_movements.occurred_at')
            ->orderBy('stock_movements.id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn ($row) => (array) $row);

        return Inertia::render('Inventory/Movements', [
            'movements' => $movements,
            'filters' => $request->only('item_id', 'from', 'to'),
            'items' => Item::query()->where('item_type', 'stock')->orderBy('name_ar')->get(['id', 'sku', 'name_ar']),
        ]);
    }

    /** الأرصدة الافتتاحية: مستند stock_opening بكمية وتكلفة موثقة. */
    public function storeOpening(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'business_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.item_unit_id' => ['required', 'integer', 'exists:item_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'gte:0'],
            'lines.*.description' => ['required', 'string', 'max:500'],
        ]);

        foreach ($data['lines'] as $index => $line) {
            $data['lines'][$index]['unit_price'] = '0';
            $data['lines'][$index]['stock_direction'] = 'in';
        }

        $document = $this->drafts->create(Document::KIND_STOCK_OPENING, $data, (int) $request->user()->id);
        $posted = $this->stockDocuments->post($document->id, (int) $request->user()->id);

        return back()->with('success', 'تم ترحيل الرصيد الافتتاحي رقم '.$posted->document_number.'.');
    }

    /** التلف والفقد وتسويات المخزون. */
    public function storeAdjustment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'business_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.item_unit_id' => ['required', 'integer', 'exists:item_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.stock_direction' => ['required', 'in:in,out'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.description' => ['required', 'string', 'max:500'],
        ]);

        foreach ($data['lines'] as $index => $line) {
            $data['lines'][$index]['unit_price'] = '0';
        }

        $document = $this->drafts->create(Document::KIND_STOCK_ADJUSTMENT, $data, (int) $request->user()->id);
        $posted = $this->stockDocuments->post($document->id, (int) $request->user()->id);

        return back()->with('success', 'تم ترحيل مستند التسوية رقم '.$posted->document_number.'.');
    }

    public function adjustments(Request $request): Response
    {
        $documents = Document::query()
            ->whereIn('kind', [Document::KIND_STOCK_ADJUSTMENT, Document::KIND_MATERIAL_USAGE, Document::KIND_STOCK_OPENING])
            ->with('lines')
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Inventory/Adjustments', [
            'documents' => $documents,
            'items' => Item::query()->where('item_type', 'stock')->where('is_active', true)
                ->with(['units' => fn ($q) => $q->where('is_active', true), 'units.unit:id,code,name'])
                ->orderBy('name_ar')->get(['id', 'sku', 'name_ar']),
            'warehouses' => Warehouse::query()->where('is_active', true)->get(['id', 'name']),
        ]);
    }
}
