<?php

declare(strict_types=1);

namespace App\Modules\Sales\Controllers;

use App\Modules\Catalog\Models\Item;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Services\PaymentAllocationService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Services\SalesPostingService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Models\DocumentLine;
use App\Shared\Documents\Services\DocumentDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** شاشة الكاشير: السلة المعلقة مستند بيع بحالة draft، والتحصيل يتم عند الاعتماد. */
class SalesController
{
    public function __construct(
        private readonly DocumentDraftService $drafts,
        private readonly SalesPostingService $posting,
        private readonly PaymentAllocationService $allocations,
    ) {}

    /** شاشة الكاشير السريعة: سلة محلية في المتصفح، وحفظ/استئناف عبر ?draft=. */
    public function cashier(Request $request): Response
    {
        $draftId = $request->integer('draft');
        $draft = null;

        if ($draftId) {
            $draft = Document::query()
                ->where('kind', Document::KIND_SALE)
                ->where('id', $draftId)
                ->with(['lines', 'party:id,name'])
                ->first();
        }

        $heldDrafts = Document::query()
            ->where('kind', Document::KIND_SALE)
            ->where('status', 'draft')
            ->when($draft, fn ($q) => $q->whereKeyNot($draft->id))
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'business_date', 'grand_total', 'party_id', 'created_at']);

        return Inertia::render('Sales/Cashier', [
            'options' => $this->options(),
            'draft' => $draft,
            'heldDrafts' => $heldDrafts,
        ]);
    }

    public function index(Request $request): Response
    {
        $documents = Document::query()
            ->with('party:id,name')
            ->where('kind', Document::KIND_SALE)
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where('document_number', 'like', "%{$s}%"))
            ->when($request->string('from')->toString(), fn ($q, $d) => $q->where('business_date', '>=', $d))
            ->when($request->string('to')->toString(), fn ($q, $d) => $q->where('business_date', '<=', $d))
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sales/Index', [
            'documents' => $documents,
            'filters' => $request->only('q', 'status', 'from', 'to'),
        ]);
    }

    /** إنشاء/تحديث السلة المعلقة (مسودة فاتورة بيع). يبقى على شاشة الكاشير نفسها. */
    public function store(Request $request): RedirectResponse
    {
        $document = $this->drafts->create(Document::KIND_SALE, $this->validated($request), (int) $request->user()->id);

        return $this->backToCashier($document->id, $request->boolean('checkout'))
            ->with('success', 'تم حفظ السلة رقم '.$document->id.'.');
    }

    public function update(Request $request, Document $sale): RedirectResponse
    {
        $this->drafts->update($sale, $this->validated($request));

        return $this->backToCashier($sale->id, $request->boolean('checkout'))
            ->with('success', 'تم تحديث السلة.');
    }

    /** يعيد الكاشير لنفس السلة، مع علامة "checkout" تفتح شاشة الدفع فور إعادة العرض. */
    private function backToCashier(int $documentId, bool $checkout): RedirectResponse
    {
        $query = $checkout ? "?draft={$documentId}&checkout=1" : "?draft={$documentId}";

        return redirect("/pos{$query}");
    }

    /** اعتماد الفاتورة: صرف المخزون، القيد المحاسبي، ودفعة/أكثر لكل وسيلة تحصيل. */
    public function post(Request $request, Document $sale): RedirectResponse
    {
        $data = $request->validate([
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'in:cash,card,bank_transfer,other'],
            'payments.*.financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'payments.*.amount' => ['required', 'numeric', 'gte:0'],
            'payments.*.external_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $payments = array_values(array_filter($data['payments'], fn ($row) => (float) $row['amount'] > 0));

        $posted = $this->posting->post($sale->id, array_map(fn ($row) => [
            'method' => $row['method'],
            'financial_account_id' => (int) $row['financial_account_id'],
            'amount' => (string) $row['amount'],
            'external_reference' => $row['external_reference'] ?? null,
        ], $payments), (int) $request->user()->id);

        return redirect("/sales/{$posted->id}")->with('success', 'تم ترحيل فاتورة البيع رقم '.$posted->document_number.'.');
    }

    public function show(Document $sale): Response
    {
        $returns = Document::query()
            ->where('original_document_id', $sale->id)
            ->where('kind', Document::KIND_SALE_RETURN)
            ->with('lines')
            ->orderByDesc('id')
            ->get();

        $returnedByLine = DB::table('document_lines')
            ->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->where('documents.kind', Document::KIND_SALE_RETURN)
            ->where('documents.status', 'posted')
            ->whereIn('document_lines.original_line_id', DocumentLine::query()->where('document_id', $sale->id)->pluck('id'))
            ->groupBy('document_lines.original_line_id')
            ->pluck(DB::raw('SUM(document_lines.quantity) as returned'), 'document_lines.original_line_id');

        return Inertia::render('Sales/Show', [
            'document' => $sale->load(['lines', 'party', 'taxTotals', 'allocations.payment', 'einvoiceDocument']),
            'outstanding' => $sale->isPosted() ? $this->allocations->outstanding($sale)->toDecimalString(2) : null,
            'returns' => $returns,
            'returnedByLine' => $returnedByLine,
            'einvoiceDevices' => \App\Modules\EInvoicing\Models\EinvoiceDevice::query()->where('is_active', true)->get(['id', 'serial_number', 'environment']),
            'financialAccounts' => FinancialAccount::query()->where('is_active', true)->get(['id', 'name', 'kind']),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'business_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'document_discount' => ['nullable', 'numeric', 'gte:0'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.item_unit_id' => ['required', 'integer', 'exists:item_units,id'],
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
            'customers' => Party::query()->where('is_customer', true)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->where('is_active', true)->get(['id', 'name']),
            'financial_accounts' => FinancialAccount::query()->where('is_active', true)->get(['id', 'name', 'kind']),
            'items' => Item::query()->where('is_active', true)
                ->with(['units' => fn ($q) => $q->where('is_active', true), 'units.unit:id,code,name', 'units.barcodes:id,item_unit_id,barcode'])
                ->orderBy('name_ar')->get(['id', 'sku', 'name_ar', 'item_type']),
        ];
    }
}
