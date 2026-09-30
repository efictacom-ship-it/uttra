<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Services\PeriodService;
use App\Modules\Parties\Models\Party;
use App\Modules\Settings\Models\BusinessSetting;
use App\Shared\Audit\AuditService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Models\DocumentLine;
use App\Shared\Documents\Services\DocumentNumberService;
use App\Shared\Documents\Services\DocumentWriter;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل مستند المصروف: لا مخزون ولا مخزن، فقط حسابات مصروف مدينة وضريبة مدخلات
 * قابلة للخصم، مقابل مستحق للمورد/الجهة أو دائن مباشر إذا سُدد نقدًا فور الترحيل
 * (عبر دفعة مستقلة تُخصَّص على المستند بعد الترحيل، كما في فاتورة الشراء).
 */
final class ExpensePostingService
{
    public function __construct(
        private readonly DocumentWriter $writer,
        private readonly JournalPostingService $journal,
        private readonly PeriodService $periods,
        private readonly AccountResolver $accounts,
        private readonly DocumentNumberService $numbers,
        private readonly AuditService $audit,
    ) {}

    public function post(int $documentId, int $userId, bool $insideTransaction = false): Document
    {
        $run = fn (): Document => $this->postInternal($documentId, $userId);

        return $insideTransaction ? $run() : DB::transaction($run, 3);
    }

    private function postInternal(int $documentId, int $userId): Document
    {
        $document = $this->writer->lockForPosting($documentId);

        if ($document->kind !== Document::KIND_EXPENSE) {
            throw new DomainException('هذه الخدمة تُرحّل مستندات المصروفات فقط.');
        }

        $period = $this->periods->openPeriodFor($document->business_date->toDateString());

        $lines = $document->lines()->get();

        if ($lines->isEmpty()) {
            throw new DomainException('لا يمكن ترحيل مصروف بلا سطور.');
        }

        $vatRegistered = (bool) BusinessSetting::current()->vat_registered;

        $expenseDebits = [];
        $recoverableTax = Money::zero();

        foreach ($lines as $line) {
            $net = Money::of($line->net_amount);
            $tax = Money::of($line->tax_amount);
            $recoverable = $vatRegistered && $line->tax_category_snapshot === 'S' ? $tax : Money::zero();

            $line->update(['tax_recoverable_amount' => $recoverable->toDb()]);

            $recoverableTax = $recoverableTax->plus($recoverable);
            $nonRecoverable = $tax->minus($recoverable);
            $costBase = $net->plus($nonRecoverable);

            $accountId = $this->expenseAccountFor($line);
            $expenseDebits[$accountId] ??= Money::zero();
            $expenseDebits[$accountId] = $expenseDebits[$accountId]->plus($costBase);
        }

        $number = $document->document_number ?? $this->numbers->next(Document::KIND_EXPENSE, $document->business_date->toDateString());
        $document = $this->writer->markPosted($document, $number, $period->id);

        $journalLines = [];

        foreach ($expenseDebits as $accountId => $amount) {
            $journalLines[] = ['account_id' => (int) $accountId, 'debit' => $amount, 'description' => 'مصروف — '.$number];
        }

        if ($recoverableTax->isPositive()) {
            $journalLines[] = [
                'account_id' => $this->accounts->id(AccountResolver::VAT_INPUT_RECOVERABLE),
                'debit' => $recoverableTax,
                'description' => 'ضريبة مدخلات — '.$number,
            ];
        }

        $journalLines[] = [
            'account_id' => $this->payableAccountFor($document),
            'credit' => Money::of($document->grand_total),
            'party_id' => $document->party_id,
            'description' => 'مستحق — مصروف '.$number,
        ];

        $this->journal->post(
            lines: $journalLines,
            entryDate: $document->business_date->toDateString(),
            description: 'مصروف '.$number,
            documentId: $document->id,
            paymentId: null,
            userId: $userId,
        );

        $this->audit->log('document', $document->id, 'expense_posted', null, [
            'document_number' => $number,
            'grand_total' => $document->grand_total,
        ]);

        return $document->refresh();
    }

    private function expenseAccountFor(DocumentLine $line): int
    {
        if ($line->account_id) {
            return (int) $line->account_id;
        }

        if ($line->item_id && $line->item?->cost_account_id) {
            return (int) $line->item->cost_account_id;
        }

        return $this->accounts->id(AccountResolver::MATERIAL_CONSUMPTION);
    }

    private function payableAccountFor(Document $document): int
    {
        if (! $document->party_id) {
            return $this->accounts->id(AccountResolver::ACCOUNTS_PAYABLE);
        }

        $party = Party::query()->find($document->party_id);

        return (int) ($party?->payable_account_id ?? $this->accounts->id(AccountResolver::ACCOUNTS_PAYABLE));
    }
}
