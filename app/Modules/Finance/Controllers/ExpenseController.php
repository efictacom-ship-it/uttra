<?php

declare(strict_types=1);

namespace App\Modules\Finance\Controllers;

use App\Modules\Accounting\Models\Account;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Services\ExpensePostingService;
use App\Modules\Finance\Services\PaymentAllocationService;
use App\Modules\Finance\Services\PaymentPostingService;
use App\Modules\Parties\Models\Party;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Services\DocumentDraftService;
use App\Shared\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** مصروفات المنشأة: إيجار، كهرباء، أدوات مكتبية... تُرحَّل فورًا، مع سداد اختياري عند الترحيل. */
class ExpenseController
{
    public function __construct(
        private readonly DocumentDraftService $drafts,
        private readonly ExpensePostingService $posting,
        private readonly PaymentPostingService $payments,
        private readonly PaymentAllocationService $allocations,
    ) {}

    public function index(Request $request): Response
    {
        $documents = Document::query()
            ->with('party:id,name')
            ->where('kind', Document::KIND_EXPENSE)
            ->when($request->string('from')->toString(), fn ($q, $d) => $q->where('business_date', '>=', $d))
            ->when($request->string('to')->toString(), fn ($q, $d) => $q->where('business_date', '<=', $d))
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $outstanding = [];
        foreach ($documents as $document) {
            $outstanding[$document->id] = $document->isPosted() ? $this->allocations->outstanding($document)->toDecimalString(2) : null;
        }

        return Inertia::render('Finance/Expenses/Index', [
            'documents' => $documents,
            'outstanding' => $outstanding,
            'filters' => $request->only('from', 'to'),
            'options' => $this->options(),
        ]);
    }

    /** إنشاء وترحيل المصروف مباشرة، مع سداد فوري اختياري. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'business_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.price_includes_tax' => ['nullable', 'boolean'],
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['nullable', 'numeric', 'gt:0'],
            'payment.method' => ['nullable', 'in:cash,card,bank_transfer,other'],
            'payment.financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
        ]);

        foreach ($data['lines'] as $index => $line) {
            $data['lines'][$index]['quantity'] = $line['quantity'] ?? '1';
        }

        $document = $this->drafts->create(Document::KIND_EXPENSE, $data, (int) $request->user()->id);
        $posted = $this->posting->post($document->id, (int) $request->user()->id);

        if (! empty($data['payment']['amount'])) {
            $this->payments->postStandalone([
                'direction' => 'disbursement',
                'method' => $data['payment']['method'] ?? 'cash',
                'financial_account_id' => $data['payment']['financial_account_id'],
                'party_id' => $posted->party_id,
                'amount' => (string) $data['payment']['amount'],
                'payment_date' => $posted->business_date->toDateString(),
                'idempotency_key' => $posted->idempotency_key.'-pay',
                'notes' => 'سداد مصروف '.$posted->document_number,
            ], [['document_id' => $posted->id, 'amount' => Money::of((string) $data['payment']['amount'])]], (int) $request->user()->id);
        }

        return back()->with('success', 'تم ترحيل المصروف رقم '.$posted->document_number.'.');
    }

    public function show(Document $expense): Response
    {
        return Inertia::render('Finance/Expenses/Show', [
            'document' => $expense->load(['lines', 'party', 'taxTotals', 'allocations.payment']),
            'outstanding' => $expense->isPosted() ? $this->allocations->outstanding($expense)->toDecimalString(2) : null,
        ]);
    }

    private function options(): array
    {
        return [
            'parties' => Party::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::query()->where('account_type', 'expense')->where('is_active', true)->where('allow_posting', true)->orderBy('code')->get(['id', 'code', 'name']),
            'financial_accounts' => FinancialAccount::query()->where('is_active', true)->get(['id', 'name', 'kind']),
        ];
    }
}
