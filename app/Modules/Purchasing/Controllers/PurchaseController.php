<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Modules\Catalog\Models\Item;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Services\PaymentAllocationService;
use App\Modules\Finance\Services\PaymentPostingService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Parties\Models\Party;
use App\Modules\Purchasing\Services\PurchasePostingService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Services\DocumentDraftService;
use App\Shared\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController
{
    public function __construct(
        private readonly DocumentDraftService $drafts,
        private readonly PurchasePostingService $posting,
        private readonly PaymentPostingService $payments,
        private readonly PaymentAllocationService $allocations,
    ) {}

    public function index(Request $request): Response
    {
        $documents = Document::query()
            ->with('party:id,name')
            ->where('kind', Document::KIND_PURCHASE)
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('document_number', 'like', "%{$s}%")
                ->orWhere('supplier_reference', 'like', "%{$s}%")))
            ->when($request->string('from')->toString(), fn ($q, $d) => $q->where('business_date', '>=', $d))
            ->when($request->string('to')->toString(), fn ($q, $d) => $q->where('business_date', '<=', $d))
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $outstanding = [];
        foreach ($documents as $document) {
            $outstanding[$document->id] = $this->allocations->outstanding($document)->toDecimalString(2);
        }

        return Inertia::render('Purchasing/Index', [
            'documents' => $documents,
            'outstanding' => $outstanding,
            'filters' => $request->only('q', 'status', 'from', 'to'),
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $document = $this->drafts->create(Document::KIND_PURCHASE, $data, (int) $request->user()->id);

        return redirect('/purchases')->with('success', 'تم حفظ مسودة فاتورة الشراء رقم الداخلي '.$document->id.'.');
    }

    public function update(Request $request, Document $purchase): RedirectResponse
    {
        $this->drafts->update($purchase, $this->validated($request));

        return back()->with('success', 'تم تحديث المسودة.');
    }

    public function post(Request $request, Document $purchase): RedirectResponse
    {
        $data = $request->validate([
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['nullable', 'numeric', 'gt:0'],
            'payment.method' => ['nullable', 'in:cash,card,bank_transfer,other'],
            'payment.financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
        ]);

        $posted = $this->posting->post($purchase->id, (int) $request->user()->id);

        if (! empty($data['payment']['amount'])) {
            $this->payments->postStandalone([
                'direction' => 'disbursement',
                'method' => $data['payment']['method'] ?? 'cash',
                'financial_account_id' => $data['payment']['financial_account_id'],
                'party_id' => $posted->party_id,
                'amount' => (string) $data['payment']['amount'],
                'payment_date' => $posted->business_date->toDateString(),
                'idempotency_key' => $posted->idempotency_key.'-supplier-pay',
                'notes' => 'سداد فاتورة شراء '.$posted->document_number,
            ], [['document_id' => $posted->id, 'amount' => Money::of((string) $data['payment']['amount'])]], (int) $request->user()->id);
        }

        return back()->with('success', 'تم ترحيل فاتورة الشراء واستلام البضاعة رقم '.$posted->document_number.'.');
    }

    public function show(Document $purchase): Response
    {
        $returns = Document::query()
            ->where('original_document_id', $purchase->id)
            ->where('kind', Document::KIND_PURCHASE_RETURN)
            ->with('lines')
            ->orderByDesc('id')
            ->get();

        $returnedByLine = DB::table('document_lines')
            ->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->where('documents.kind', Document::KIND_PURCHASE_RETURN)
            ->where('documents.status', 'posted')
            ->whereIn('document_lines.original_line_id', $purchase->lines()->pluck('id'))
            ->groupBy('document_lines.original_line_id')
            ->pluck(DB::raw('SUM(document_lines.quantity) as returned'), 'document_lines.original_line_id');

        return Inertia::render('Purchasing/Show', [
            'document' => $purchase->load(['lines', 'party', 'taxTotals', 'allocations.payment']),
            'outstanding' => $this->allocations->outstanding($purchase)->toDecimalString(2),
            'returns' => $returns,
            'returnedByLine' => $returnedByLine,
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'business_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:business_date'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'document_discount' => ['nullable', 'numeric', 'gte:0'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'lines.*.item_unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.price_includes_tax' => ['nullable', 'boolean'],
        ]);
    }

    private function options(): array
    {
        return [
            'suppliers' => Party::query()->where('is_supplier', true)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->where('is_active', true)->get(['id', 'name']),
            'financial_accounts' => FinancialAccount::query()->where('is_active', true)->get(['id', 'name', 'kind']),
            'items' => Item::query()->where('is_active', true)->with(['units' => fn ($q) => $q->where('is_active', true), 'units.unit:id,code,name'])
                ->orderBy('name_ar')->get(['id', 'sku', 'name_ar', 'item_type']),
        ];
    }
}
