<?php

declare(strict_types=1);

namespace App\Modules\Finance\Controllers;

use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentPostingService;
use App\Modules\Parties\Models\Party;
use App\Shared\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** سندات القبض والصرف المستقلة، والتحويل بين الحسابات المالية. */
class PaymentController
{
    public function __construct(
        private readonly PaymentPostingService $payments,
    ) {}

    public function index(Request $request): Response
    {
        $payments = Payment::query()
            ->with(['financialAccount:id,name', 'party:id,name'])
            ->when($request->string('direction')->toString(), fn ($q, $d) => $q->where('direction', $d))
            ->when($request->integer('party_id'), fn ($q, $id) => $q->where('party_id', $id))
            ->when($request->string('from')->toString(), fn ($q, $d) => $q->where('payment_date', '>=', $d))
            ->when($request->string('to')->toString(), fn ($q, $d) => $q->where('payment_date', '<=', $d))
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Finance/Payments/Index', [
            'payments' => $payments,
            'filters' => $request->only('direction', 'party_id', 'from', 'to'),
            'financialAccounts' => FinancialAccount::query()->where('is_active', true)->get(['id', 'name', 'kind']),
            'parties' => Party::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** سند قبض/صرف مستقل، مع إمكانية تخصيصه على مستند واحد أو أكثر فورًا. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:receipt,disbursement'],
            'method' => ['required', 'in:cash,card,bank_transfer,other'],
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.document_id' => ['required_with:allocations', 'integer', 'exists:documents,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'gt:0'],
        ]);

        $allocations = array_map(fn ($row) => [
            'document_id' => (int) $row['document_id'],
            'amount' => Money::of((string) $row['amount']),
        ], $data['allocations'] ?? []);

        $payment = $this->payments->postStandalone($data, $allocations, (int) $request->user()->id);

        return back()->with('success', 'تم ترحيل السند رقم '.$payment->payment_number.'.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id', 'different:destination_financial_account_id'],
            'destination_financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'fee_amount' => ['nullable', 'numeric', 'gte:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['nullable', 'in:cash,card,bank_transfer,other'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $this->payments->postTransfer($data, (int) $request->user()->id);

        return back()->with('success', 'تم ترحيل التحويل رقم '.$payment->payment_number.'.');
    }

    public function show(Payment $payment): Response
    {
        return Inertia::render('Finance/Payments/Show', [
            'payment' => $payment->load(['financialAccount', 'destinationAccount', 'party', 'allocations.document:id,document_number,kind,grand_total']),
        ]);
    }

    /** يخصص دفعة موجودة (غير مخصصة بالكامل) على مستند إضافي. */
    public function allocate(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate([
            'document_id' => ['required', 'integer', 'exists:documents,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        app(\App\Modules\Finance\Services\PaymentAllocationService::class)->allocate($payment, [[
            'document_id' => (int) $data['document_id'],
            'amount' => Money::of((string) $data['amount']),
        ]]);

        return back()->with('success', 'تم تخصيص الدفعة.');
    }
}
