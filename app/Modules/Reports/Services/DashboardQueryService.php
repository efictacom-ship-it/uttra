<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Finance\Models\CashSession;
use App\Shared\Documents\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * قراءات الصفحة الرئيسية. يوم العمل بتوقيت Asia/Riyadh، والتخزين UTC.
 * تفرّق بين المبيعات (فواتير) والتحصيل (دفعات) ولا تعد الضريبة إيرادًا.
 */
final class DashboardQueryService
{
    public function today(): array
    {
        $day = Carbon::now('Asia/Riyadh')->toDateString();

        $sales = Document::query()
            ->where('status', 'posted')
            ->whereIn('kind', [Document::KIND_SALE, Document::KIND_SALE_RETURN])
            ->where('business_date', $day)
            ->selectRaw("
                SUM(CASE WHEN kind = 'sale' THEN net_total ELSE -net_total END) AS net_sales,
                SUM(CASE WHEN kind = 'sale' THEN tax_total ELSE -tax_total END) AS tax_total,
                SUM(CASE WHEN kind = 'sale' THEN grand_total ELSE -grand_total END) AS gross_total,
                SUM(CASE WHEN kind = 'sale' THEN 1 ELSE 0 END) AS invoices,
                SUM(CASE WHEN kind = 'sale_return' THEN 1 ELSE 0 END) AS returns
            ")
            ->first();

        $collections = DB::table('payments')
            ->where('status', 'posted')
            ->where('payment_date', $day)
            ->whereIn('direction', ['receipt', 'disbursement'])
            ->selectRaw("
                method,
                SUM(CASE WHEN direction = 'receipt' THEN amount ELSE -amount END) AS amount
            ")
            ->groupBy('method')
            ->pluck('amount', 'method');

        return [
            'date' => $day,
            'net_sales' => (string) ($sales->net_sales ?? '0'),
            'tax_total' => (string) ($sales->tax_total ?? '0'),
            'gross_total' => (string) ($sales->gross_total ?? '0'),
            'invoices' => (int) ($sales->invoices ?? 0),
            'returns' => (int) ($sales->returns ?? 0),
            'collections_by_method' => $collections,
        ];
    }

    /** المتاح بعد الحجوزات مقارنةً بحد إعادة الطلب. */
    public function lowStock(int $limit = 8): array
    {
        return DB::table('items')
            ->join('stock_balances', 'stock_balances.item_id', '=', 'items.id')
            ->leftJoin(DB::raw('(SELECT item_id, warehouse_id, SUM(quantity) AS reserved FROM stock_reservations WHERE status = \'active\' GROUP BY item_id, warehouse_id) r'),
                function ($join) {
                    $join->on('r.item_id', '=', 'stock_balances.item_id')->on('r.warehouse_id', '=', 'stock_balances.warehouse_id');
                })
            ->where('items.item_type', 'stock')
            ->where('items.is_active', true)
            ->whereRaw('(stock_balances.quantity - COALESCE(r.reserved, 0)) <= items.reorder_level')
            ->orderByRaw('(stock_balances.quantity - COALESCE(r.reserved, 0)) ASC')
            ->limit($limit)
            ->get([
                'items.id', 'items.sku', 'items.name_ar', 'items.reorder_level',
                DB::raw('(stock_balances.quantity - COALESCE(r.reserved, 0)) AS available'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** أرصدة العملاء المستحقة حسب المستندات المرحلة ناقص التخصيصات. */
    public function overdueReceivables(int $limit = 8): array
    {
        return DB::table('documents')
            ->join('parties', 'parties.id', '=', 'documents.party_id')
            ->leftJoin(DB::raw('(SELECT document_id, SUM(amount) AS paid FROM payment_allocations GROUP BY document_id) pa'), 'pa.document_id', '=', 'documents.id')
            ->where('documents.status', 'posted')
            ->where('documents.kind', Document::KIND_SALE)
            ->whereRaw('documents.grand_total - COALESCE(pa.paid, 0) > 0.005')
            ->orderBy('documents.due_date')
            ->limit($limit)
            ->get([
                'documents.id', 'documents.document_number', 'documents.due_date', 'parties.name AS party_name',
                DB::raw('documents.grand_total - COALESCE(pa.paid, 0) AS outstanding'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function einvoiceProblems(int $limit = 8): array
    {
        return DB::table('einvoice_documents')
            ->join('documents', 'documents.id', '=', 'einvoice_documents.document_id')
            ->whereIn('einvoice_documents.status', ['rejected', 'connection_error', 'pending'])
            ->orderBy('einvoice_documents.reporting_due_at')
            ->limit($limit)
            ->get([
                'einvoice_documents.id', 'einvoice_documents.status', 'einvoice_documents.reporting_due_at',
                'documents.document_number',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function openCashSession(): ?array
    {
        $session = CashSession::query()->with('financialAccount:id,name')->whereNull('closed_at')->first();

        return $session ? [
            'id' => $session->id,
            'account' => $session->financialAccount?->name,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'opening_cash' => $session->opening_cash,
        ] : null;
    }
}
