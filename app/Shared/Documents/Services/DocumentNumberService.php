<?php

declare(strict_types=1);

namespace App\Shared\Documents\Services;

use Illuminate\Support\Facades\DB;

/**
 * تخصيص الرقم التجاري مرة واحدة عند الإصدار، بقفل صف التسلسل داخل المعاملة.
 */
final class DocumentNumberService
{
    private const PREFIXES = [
        'sale' => 'INV',
        'sale_return' => 'CRN',
        'purchase' => 'PUR',
        'purchase_return' => 'PRN',
        'expense' => 'EXP',
        'stock_opening' => 'OPN',
        'stock_adjustment' => 'ADJ',
        'material_usage' => 'MAT',
        'payment' => 'PAY',
        'journal' => 'JV',
    ];

    public function next(string $kind, string $businessDate): string
    {
        $year = (int) substr($businessDate, 0, 4);
        $prefix = self::PREFIXES[$kind] ?? strtoupper(substr($kind, 0, 3));

        $row = DB::table('document_sequences')
            ->where('kind', $kind)
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            DB::table('document_sequences')->insertOrIgnore([
                'kind' => $kind,
                'fiscal_year' => $year,
                'prefix' => $prefix,
                'next_number' => 1,
            ]);

            $row = DB::table('document_sequences')
                ->where('kind', $kind)
                ->where('fiscal_year', $year)
                ->lockForUpdate()
                ->first();
        }

        $number = (int) $row->next_number;

        DB::table('document_sequences')
            ->where('id', $row->id)
            ->update(['next_number' => $number + 1]);

        return sprintf('%s-%d-%06d', $row->prefix, $year, $number);
    }
}
