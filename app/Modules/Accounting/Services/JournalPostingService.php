<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Shared\Documents\Services\DocumentNumberService;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * قيد واحد متوازن لكل مصدر (مستند أو دفعة). لا يُرحَّل قيد غير متوازن،
 * ولا يُرحَّل مصدر مرتين (UNIQUE على document_id وعلى payment_id).
 */
final class JournalPostingService
{
    public function __construct(
        private readonly PeriodService $periods,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * @param  list<array{account_id:int, debit?:Money, credit?:Money, party_id?:int|null, description?:string|null}>  $lines
     */
    public function post(
        array $lines,
        string $entryDate,
        string $description,
        ?int $documentId,
        ?int $paymentId,
        int $userId,
        ?int $reversesEntryId = null,
    ): JournalEntry {
        if ($documentId !== null && $paymentId !== null) {
            throw new DomainException('القيد يرتبط بمستند أو بدفعة، لا بالاثنين معًا.');
        }

        $lines = $this->mergeAndClean($lines);

        if (count($lines) < 2) {
            throw new DomainException('القيد يحتاج سطرين على الأقل.');
        }

        $debit = Money::zero();
        $credit = Money::zero();

        foreach ($lines as $line) {
            $debit = $debit->plus($line['debit']);
            $credit = $credit->plus($line['credit']);

            $account = Account::query()->find($line['account_id']);

            if (! $account || ! $account->is_active || ! $account->allow_posting) {
                throw new DomainException('لا يمكن الترحيل على حساب غير موجود أو موقوف أو غير قابل للترحيل.');
            }
        }

        if (! $debit->equals($credit)) {
            throw new DomainException(
                'القيد غير متوازن: مدين '.$debit->toDecimalString(2).' مقابل دائن '.$credit->toDecimalString(2).'.'
            );
        }

        if ($debit->isZero()) {
            throw new DomainException('لا يمكن ترحيل قيد بقيمة صفر.');
        }

        $period = $this->periods->openPeriodFor($entryDate);

        if ($documentId !== null && JournalEntry::query()->where('document_id', $documentId)->exists()) {
            throw new DomainException('المستند مرحّل محاسبيًا بالفعل.');
        }

        if ($paymentId !== null && JournalEntry::query()->where('payment_id', $paymentId)->exists()) {
            throw new DomainException('الدفعة مرحّلة محاسبيًا بالفعل.');
        }

        $entry = JournalEntry::query()->create([
            'entry_number' => $this->numbers->next('journal', $entryDate),
            'document_id' => $documentId,
            'payment_id' => $paymentId,
            'reverses_entry_id' => $reversesEntryId,
            'accounting_period_id' => $period->id,
            'entry_date' => $entryDate,
            'description' => $description,
            'status' => 'posted',
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        $rows = [];
        foreach (array_values($lines) as $index => $line) {
            $rows[] = [
                'journal_entry_id' => $entry->id,
                'line_number' => $index + 1,
                'account_id' => $line['account_id'],
                'party_id' => $line['party_id'] ?? null,
                'debit' => $line['debit']->toDb(),
                'credit' => $line['credit']->toDb(),
                'description' => $line['description'] ?? null,
            ];
        }

        DB::table('journal_lines')->insert($rows);

        return $entry;
    }

    /** يجمع سطور نفس (الحساب + الطرف) ويحذف الأصفار ويحوّل السالب لاتجاهه الصحيح. */
    private function mergeAndClean(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $debit = $line['debit'] ?? Money::zero();
            $credit = $line['credit'] ?? Money::zero();

            if ($debit->isNegative()) {
                $credit = $credit->plus($debit->abs());
                $debit = Money::zero();
            }

            if ($credit->isNegative()) {
                $debit = $debit->plus($credit->abs());
                $credit = Money::zero();
            }

            $key = $line['account_id'].'|'.($line['party_id'] ?? '0').'|'.($line['description'] ?? '');

            $merged[$key] ??= [
                'account_id' => (int) $line['account_id'],
                'party_id' => $line['party_id'] ?? null,
                'description' => $line['description'] ?? null,
                'debit' => Money::zero(),
                'credit' => Money::zero(),
            ];

            $merged[$key]['debit'] = $merged[$key]['debit']->plus($debit);
            $merged[$key]['credit'] = $merged[$key]['credit']->plus($credit);
        }

        $result = [];

        foreach ($merged as $line) {
            // صافي الطرفين على نفس الحساب: يبقى اتجاه واحد فقط
            if ($line['debit']->isGreaterThan($line['credit'])) {
                $line['debit'] = $line['debit']->minus($line['credit']);
                $line['credit'] = Money::zero();
            } else {
                $line['credit'] = $line['credit']->minus($line['debit']);
                $line['debit'] = Money::zero();
            }

            if ($line['debit']->isZero() && $line['credit']->isZero()) {
                continue;
            }

            $result[] = $line;
        }

        return $result;
    }
}
