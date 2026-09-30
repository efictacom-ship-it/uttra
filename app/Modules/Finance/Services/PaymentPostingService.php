<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Services\PeriodService;
use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Models\Payment;
use App\Modules\Parties\Models\Party;
use App\Shared\Audit\AuditService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Services\DocumentNumberService;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * الدفعة مستند مالي مستقل بقيدها الخاص، فلا يتكرر التحصيل ولا الإيراد:
 * - دفعة بيع: مدين الصندوق/البنك/الشبكة، دائن نفس حساب الفاتورة (تحكم الكاشير أو العميل).
 * - سداد مورد: مدين المورد، دائن الحساب المالي.
 * - تحويل: مدين الوجهة (والرسوم مصروفًا) ودائن المصدر — ليس إيرادًا ولا مصروف تشغيل.
 */
final class PaymentPostingService
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly PeriodService $periods,
        private readonly AccountResolver $accounts,
        private readonly DocumentNumberService $numbers,
        private readonly PaymentAllocationService $allocations,
        private readonly AuditService $audit,
    ) {}

    /** دفعة مرافقة لفاتورة بيع (نقدي/شبكة/تحويل ضمن شاشة الكاشير). */
    public function postSaleReceipt(
        Document $document,
        string $method,
        int $financialAccountId,
        Money $amount,
        int $userId,
        ?string $externalReference = null,
        ?string $idempotencyKey = null,
    ): Payment {
        $payment = $this->createPayment([
            'direction' => 'receipt',
            'method' => $method,
            'financial_account_id' => $financialAccountId,
            'party_id' => $document->party_id,
            'amount' => $amount,
            'payment_date' => $document->business_date->toDateString(),
            'counter_account_id' => $this->accounts->id(AccountResolver::POS_SALES_CONTROL),
            'idempotency_key' => $idempotencyKey,
            'notes' => 'تحصيل فاتورة '.$document->document_number,
        ], $userId);

        $this->allocations->allocate($payment, [['document_id' => $document->id, 'amount' => $amount]]);
        $this->postJournalFor($payment, $userId);

        return $payment->refresh();
    }

    /**
     * سند قبض/صرف مستقل مع تخصيص على مستندات أو إبقائه مقدمًا غير مخصص.
     *
     * @param  list<array{document_id:int, amount:string|Money}>  $allocations
     */
    public function postStandalone(array $data, array $allocations, int $userId): Payment
    {
        return DB::transaction(function () use ($data, $allocations, $userId): Payment {
            $amount = Money::of($data['amount']);
            $direction = $data['direction'];

            if (! in_array($direction, ['receipt', 'disbursement'], true)) {
                throw new DomainException('اتجاه الدفعة غير صحيح.');
            }

            $partyId = $data['party_id'] ?? null;
            $counterAccountId = $data['counter_account_id'] ?? null;

            if ($allocations === [] && $counterAccountId === null) {
                // دفعة مقدمة غير مخصصة: تذهب لحساب الدفعات المقدمة حسب الاتجاه
                $counterAccountId = $direction === 'receipt'
                    ? $this->accounts->id(AccountResolver::CUSTOMER_ADVANCES)
                    : $this->accounts->id(AccountResolver::SUPPLIER_ADVANCES);

                if (! $partyId) {
                    throw new DomainException('الدفعة المقدمة تحتاج طرفًا.');
                }
            }

            $payment = $this->createPayment([
                'direction' => $direction,
                'method' => $data['method'],
                'financial_account_id' => (int) $data['financial_account_id'],
                'party_id' => $partyId,
                'amount' => $amount,
                'payment_date' => $data['payment_date'],
                'counter_account_id' => $counterAccountId,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'external_reference' => $data['external_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], $userId);

            if ($allocations !== []) {
                $this->allocations->allocate($payment, $allocations);
            }

            $this->postJournalFor($payment, $userId);

            return $payment->refresh();
        });
    }

    /** تحويل بين حسابين ماليين، مع رسوم اختيارية (تسوية الشبكة إلى البنك). */
    public function postTransfer(array $data, int $userId): Payment
    {
        return DB::transaction(function () use ($data, $userId): Payment {
            $amount = Money::of($data['amount']);
            $fee = Money::of($data['fee_amount'] ?? '0');

            if ($fee->isGreaterThanOrEqualTo($amount)) {
                throw new DomainException('الرسوم يجب أن تكون أقل من المبلغ المحوَّل.');
            }

            $source = FinancialAccount::query()->findOrFail($data['financial_account_id']);
            $destination = FinancialAccount::query()->findOrFail($data['destination_financial_account_id']);

            if ($source->id === $destination->id) {
                throw new DomainException('لا يمكن التحويل إلى الحساب نفسه.');
            }

            $payment = $this->createPayment([
                'direction' => 'transfer',
                'method' => $data['method'] ?? 'bank_transfer',
                'financial_account_id' => $source->id,
                'destination_financial_account_id' => $destination->id,
                'amount' => $amount,
                'fee_amount' => $fee,
                'fee_account_id' => $fee->isPositive() ? $this->accounts->id(AccountResolver::CARD_SETTLEMENT_FEE) : null,
                'payment_date' => $data['payment_date'],
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'notes' => $data['notes'] ?? 'تحويل بين الحسابات المالية',
            ], $userId);

            $this->postJournalFor($payment, $userId);

            return $payment->refresh();
        });
    }

    /** رد مبلغ مرتبط بمستند (مثل رد نقدية مرتجع بيع). */
    public function postRefund(Document $returnDocument, string $method, int $financialAccountId, Money $amount, int $userId, ?string $idempotencyKey = null): Payment
    {
        $payment = $this->createPayment([
            'direction' => 'disbursement',
            'method' => $method,
            'financial_account_id' => $financialAccountId,
            'party_id' => $returnDocument->party_id,
            'amount' => $amount,
            'payment_date' => $returnDocument->business_date->toDateString(),
            'counter_account_id' => $this->accounts->id(AccountResolver::POS_SALES_CONTROL),
            'idempotency_key' => $idempotencyKey,
            'notes' => 'رد قيمة مرتجع '.$returnDocument->document_number,
        ], $userId);

        $this->allocations->allocate($payment, [['document_id' => $returnDocument->id, 'amount' => $amount]]);
        $this->postJournalFor($payment, $userId);

        return $payment->refresh();
    }

    private function createPayment(array $attributes, int $userId): Payment
    {
        $amount = $attributes['amount'] instanceof Money ? $attributes['amount'] : Money::of($attributes['amount']);

        if (! $amount->isPositive()) {
            throw new DomainException('قيمة الدفعة يجب أن تكون أكبر من صفر.');
        }

        $paymentDate = $attributes['payment_date'];
        $period = $this->periods->openPeriodFor($paymentDate);

        $key = $attributes['idempotency_key'] ?? (string) \Illuminate\Support\Str::uuid();
        $existing = Payment::query()->where('idempotency_key', $key)->first();

        if ($existing) {
            return $existing;
        }

        $fee = $attributes['fee_amount'] ?? Money::zero();

        $payment = Payment::query()->create([
            'payment_number' => $this->numbers->next('payment', $paymentDate),
            'direction' => $attributes['direction'],
            'financial_account_id' => $attributes['financial_account_id'],
            'destination_financial_account_id' => $attributes['destination_financial_account_id'] ?? null,
            'party_id' => $attributes['party_id'] ?? null,
            'cash_session_id' => $this->currentCashSessionId((int) $attributes['financial_account_id']),
            'accounting_period_id' => $period->id,
            'counter_account_id' => $attributes['counter_account_id'] ?? null,
            'method' => $attributes['method'],
            'amount' => $amount->toDb(),
            'fee_amount' => $fee instanceof Money ? $fee->toDb() : (string) $fee,
            'fee_account_id' => $attributes['fee_account_id'] ?? null,
            'payment_date' => $paymentDate,
            'paid_at' => now(),
            'status' => 'posted',
            'external_reference' => $attributes['external_reference'] ?? null,
            'idempotency_key' => $key,
            'notes' => $attributes['notes'] ?? null,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        $this->audit->log('payment', $payment->id, 'created', null, ['amount' => $amount->toDb(), 'direction' => $payment->direction]);

        return $payment;
    }

    private function postJournalFor(Payment $payment, int $userId): void
    {
        $amount = Money::of($payment->amount);
        $financialAccount = FinancialAccount::query()->findOrFail($payment->financial_account_id);
        $lines = [];

        if ($payment->direction === 'transfer') {
            $destination = FinancialAccount::query()->findOrFail($payment->destination_financial_account_id);
            $fee = Money::of($payment->fee_amount);

            $lines[] = ['account_id' => (int) $destination->ledger_account_id, 'debit' => $amount->minus($fee), 'description' => 'تحويل وارد'];

            if ($fee->isPositive()) {
                $lines[] = ['account_id' => (int) $payment->fee_account_id, 'debit' => $fee, 'description' => 'رسوم تسوية'];
            }

            $lines[] = ['account_id' => (int) $financialAccount->ledger_account_id, 'credit' => $amount, 'description' => 'تحويل صادر'];
        } elseif ($payment->direction === 'receipt') {
            $lines[] = ['account_id' => (int) $financialAccount->ledger_account_id, 'debit' => $amount, 'description' => 'تحصيل'];
            $lines[] = [
                'account_id' => $this->counterAccountFor($payment),
                'credit' => $amount,
                'party_id' => $payment->party_id,
                'description' => 'إقفال مديونية/تحكم مبيعات',
            ];
        } else {
            $lines[] = [
                'account_id' => $this->counterAccountFor($payment),
                'debit' => $amount,
                'party_id' => $payment->party_id,
                'description' => 'سداد/رد',
            ];
            $lines[] = ['account_id' => (int) $financialAccount->ledger_account_id, 'credit' => $amount, 'description' => 'صرف'];
        }

        $this->journal->post(
            lines: $lines,
            entryDate: $payment->payment_date->toDateString(),
            description: 'دفعة '.$payment->payment_number,
            documentId: null,
            paymentId: $payment->id,
            userId: $userId,
        );
    }

    /** الحساب المقابل: نفس حساب الفاتورة المخصصة، أو المحدد صراحة، أو حساب الطرف. */
    private function counterAccountFor(Payment $payment): int
    {
        if ($payment->counter_account_id) {
            return (int) $payment->counter_account_id;
        }

        $allocation = $payment->allocations()->with('document')->first();

        if ($allocation?->document) {
            $kind = $allocation->document->kind;

            if (in_array($kind, [Document::KIND_PURCHASE, Document::KIND_PURCHASE_RETURN, Document::KIND_EXPENSE], true)) {
                return $this->payableAccountFor($payment->party_id);
            }

            return $this->receivableAccountFor($payment->party_id);
        }

        return $payment->direction === 'receipt'
            ? $this->receivableAccountFor($payment->party_id)
            : $this->payableAccountFor($payment->party_id);
    }

    private function receivableAccountFor(?int $partyId): int
    {
        $party = $partyId ? Party::query()->find($partyId) : null;

        return (int) ($party?->receivable_account_id ?? $this->accounts->id(AccountResolver::ACCOUNTS_RECEIVABLE));
    }

    private function payableAccountFor(?int $partyId): int
    {
        $party = $partyId ? Party::query()->find($partyId) : null;

        return (int) ($party?->payable_account_id ?? $this->accounts->id(AccountResolver::ACCOUNTS_PAYABLE));
    }

    private function currentCashSessionId(int $financialAccountId): ?int
    {
        return CashSession::query()
            ->where('financial_account_id', $financialAccountId)
            ->whereNull('closed_at')
            ->value('id');
    }
}
