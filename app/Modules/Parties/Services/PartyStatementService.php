<?php

declare(strict_types=1);

namespace App\Modules\Parties\Services;

use App\Modules\Parties\Models\Party;
use Illuminate\Support\Facades\DB;

/**
 * أرصدة الأطراف تُقرأ من القيود المرحلة (journal_lines) لا من عمود رصيد على البطاقة.
 * لا مقاصة تلقائية بين كون الطرف عميلًا وموردًا: كل جهة تُعرض على حدة.
 */
final class PartyStatementService
{
    /**
     * @param  list<int>  $partyIds
     * @return array<int, array{receivable: string, payable: string}>
     */
    public function balancesFor(array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->whereIn('journal_lines.party_id', $partyIds)
            ->groupBy('journal_lines.party_id', 'accounts.account_type')
            ->get([
                'journal_lines.party_id',
                'accounts.account_type',
                DB::raw('SUM(journal_lines.debit - journal_lines.credit) AS net'),
            ]);

        $result = [];
        foreach ($partyIds as $id) {
            $result[$id] = ['receivable' => '0.00', 'payable' => '0.00'];
        }

        foreach ($rows as $row) {
            $net = (string) $row->net;
            if ($row->account_type === 'asset') {
                $result[$row->party_id]['receivable'] = $net;
            } elseif ($row->account_type === 'liability') {
                $result[$row->party_id]['payable'] = bcmul($net, '-1', 6);
            }
        }

        return $result;
    }

    /** كشف حساب بالحركة والرصيد التراكمي. */
    public function statement(Party $party, string $from, string $to): array
    {
        $opening = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_lines.party_id', $party->id)
            ->where('journal_entries.entry_date', '<', $from)
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));

        $lines = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->leftJoin('documents', 'documents.id', '=', 'journal_entries.document_id')
            ->leftJoin('payments', 'payments.id', '=', 'journal_entries.payment_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_lines.party_id', $party->id)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->get([
                'journal_entries.entry_date',
                'journal_entries.description',
                'accounts.code AS account_code',
                'accounts.name AS account_name',
                'journal_lines.debit',
                'journal_lines.credit',
                'documents.document_number',
                'documents.kind AS document_kind',
                'payments.payment_number',
            ]);

        $running = (string) $opening;
        $rows = [];

        foreach ($lines as $line) {
            $running = bcadd($running, bcsub((string) $line->debit, (string) $line->credit, 6), 6);
            $rows[] = array_merge((array) $line, ['balance' => $running]);
        }

        return [
            'from' => $from,
            'to' => $to,
            'opening_balance' => (string) $opening,
            'closing_balance' => $running,
            'lines' => $rows,
        ];
    }
}
