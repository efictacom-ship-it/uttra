<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockCountService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** واجهة HTTP رقيقة فوق StockCountService؛ كل منطق الجرد وقواعده في الخدمة. */
class StockCountController
{
    public function __construct(
        private readonly StockCountService $counts,
    ) {}

    public function index(Request $request): Response
    {
        $counts = StockCount::query()
            ->with('warehouse:id,name')
            ->when($request->integer('warehouse_id'), fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Inventory/StockCounts/Index', [
            'counts' => $counts,
            'filters' => $request->only('warehouse_id', 'status'),
            'warehouses' => Warehouse::query()->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'business_date' => ['nullable', 'date'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer', 'exists:items,id'],
        ]);

        try {
            $stockCount = $this->counts->create(
                (int) $data['warehouse_id'],
                array_map('intval', $data['item_ids'] ?? []),
                $data['business_date'] ?? now()->toDateString(),
                (int) $request->user()->id,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['warehouse_id' => [$e->getMessage()]]);
        }

        return redirect("/stock-counts/{$stockCount->id}")->with('success', 'تم إنشاء جلسة الجرد؛ حركة هذا المخزن مجمّدة حتى الاعتماد أو الإلغاء.');
    }

    public function show(StockCount $stockCount): Response
    {
        return Inertia::render('Inventory/StockCounts/Show', [
            'stockCount' => $stockCount->load(['warehouse:id,name', 'adjustmentDocument:id,document_number', 'lines.item:id,sku,name_ar']),
        ]);
    }

    public function update(Request $request, StockCount $stockCount): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.item_id' => ['required_with:lines', 'integer'],
            'lines.*.counted_quantity' => ['nullable', 'numeric', 'gte:0'],
        ]);

        try {
            $this->counts->recordCounts($stockCount, $data['lines'] ?? [], (int) $request->user()->id);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['lines' => [$e->getMessage()]]);
        }

        return back()->with('success', 'تم تحديث بيانات الجرد.');
    }

    public function post(Request $request, StockCount $stockCount): RedirectResponse
    {
        try {
            $posted = $this->counts->post($stockCount->id, (int) $request->user()->id);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return back()->with('success', 'تم اعتماد الجرد'.($posted->adjustment_document_id ? ' وترحيل فروقاته.' : ' بلا فروقات.'));
    }

    public function cancel(Request $request, StockCount $stockCount): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $this->counts->cancel($stockCount->id, $data['reason'] ?? 'إلغاء يدوي', (int) $request->user()->id);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return back()->with('success', 'تم إلغاء جلسة الجرد؛ حركة المخزن غير مجمّدة الآن.');
    }
}
