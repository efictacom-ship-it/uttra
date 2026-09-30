<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Services\AccountResolver;
use App\Shared\Documents\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * تقارير الإدارة: المبيعات، المخزون، الأعمار، الأستاذ، والضريبة. كل تقرير يُقرأ
 * من مصدر الحقيقة نفسه المستخدم في الترحيل (journal_lines / stock_movements /
 * documents) لا من أعمدة رصيد مخزَّنة، فلا يمكن أن يختلف التقرير عمّا رُحِّل فعلًا.
 * المبيعات (الفواتير) والتحصيل (الدفعات) والربح (الهامش) ثلاثة أرقام مختلفة دائمًا،
 * والضريبة لا تُحسب إيرادًا في أي تقرير هنا.
 */
final class ReportService
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /** ملخص المبيعات: يومي أو شهري حسب $groupBy، صافي بعد المرتجعات. */
    public function salesSummary(string $from, string $to, string $groupBy = 'day'): array
    {
        $format = $groupBy === 'month' ? '%Y-%m' : '%Y-%m-%d';

        return DB::table('documents')
            ->where('status', 'posted')
            ->whereIn('kind', [Document::KIND_SALE, Document::KIND_SALE_RETURN])
            ->whereBetween('business_date', [$from, $to])
            ->selectRaw("DATE_FORMAT(business_date, '{$format}') AS period")
            ->selectRaw("SUM(CASE WHEN kind = 'sale' THEN net_total ELSE -net_total END) AS net_sales")
            ->selectRaw("SUM(CASE WHEN kind = 'sale' THEN tax_total ELSE -tax_total END) AS tax_total")
            ->selectRaw("SUM(CASE WHEN kind = 'sale' THEN grand_total ELSE -grand_total END) AS gross_total")
            ->selectRaw("SUM(CASE WHEN kind = 'sale' THEN 1 ELSE 0 END) AS invoices")
            ->selectRaw("SUM(CASE WHEN kind = 'sale_return' THEN 1 ELSE 0 END) AS returns")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** المبيعات مجمّعة حسب الصنف (سلعة أو خدمة)، صافيًا بعد المرتجعات. */
    public function salesByItem(string $from, string $to, ?string $itemType = null): array
    {
        return DB::table('document_lines')
            ->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->join('items', 'items.id', '=', 'document_lines.item_id')
            ->where('documents.status', 'posted')
            ->whereIn('documents.kind', [Document::KIND_SALE, Document::KIND_SALE_RETURN])
            ->whereBetween('documents.business_date', [$from, $to])
            ->when($itemType, fn ($q, $t) => $q->where('items.item_type', $t))
            ->groupBy('items.id', 'items.sku', 'items.name_ar')
            ->orderByDesc(DB::raw("SUM(CASE WHEN documents.kind = 'sale' THEN document_lines.net_amount ELSE -document_lines.net_amount END)"))
            ->get([
                'items.id', 'items.sku', 'items.name_ar',
                DB::raw("SUM(CASE WHEN documents.kind = 'sale' THEN document_lines.base_quantity ELSE -document_lines.base_quantity END) AS quantity"),
                DB::raw("SUM(CASE WHEN documents.kind = 'sale' THEN document_lines.net_amount ELSE -document_lines.net_amount END) AS net_sales"),
                DB::raw("SUM(CASE WHEN documents.kind = 'sale' THEN COALESCE(document_lines.total_cost_snapshot,0) ELSE -COALESCE(document_lines.total_cost_snapshot,0) END) AS cost"),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** المبيعات مجمّعة حسب التصنيف. */
    public function salesByCategory(string $from, string $to): array
    {
        return DB::table('document_lines')
            ->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->join('items', 'items.id', '=', 'document_lines.item_id')
            ->leftJoin('categories', 'categories.id', '=', 'items.category_id')
            ->where('documents.status', 'posted')
            ->whereIn('documents.kind', [Document::KIND_SALE, Document::KIND_SALE_RETURN])
            ->whereBetween('documents.business_date', [$from, $to])
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc(DB::raw("SUM(CASE WHEN documents.kind = 'sale' THEN document_lines.net_amount ELSE -document_lines.net_amount END)"))
            ->get([
                'categories.id AS category_id',
                DB::raw("COALESCE(categories.name, 'بدون تصنيف') AS category_name"),
                DB::raw("SUM(CASE WHEN documents.kind = 'sale' THEN document_lines.net_amount ELSE -document_lines.net_amount END) AS net_sales"),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** التحصيل الفعلي حسب وسيلة الدفع، مستقل تمامًا عن رقم المبيعات. */
    public function collectionsByMethod(string $from, string $to): array
    {
        return DB::table('payments')
            ->where('status', 'posted')
            ->whereBetween('payment_date', [$from, $to])
            ->whereIn('direction', ['receipt', 'disbursement'])
            ->selectRaw("method, direction, SUM(amount) AS amount, COUNT(*) AS count")
            ->groupBy('method', 'direction')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** تكلفة المبيعات والهامش الإجمالي = صافي المبيعات − التكلفة، بعد أثر المرتجعات. */
    public function costOfGoodsSoldAndMargin(string $from, string $to): array
    {
        $row = DB::table('document_lines')
            ->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->where('documents.status', 'posted')
            ->whereIn('documents.kind', [Document::KIND_SALE, Document::KIND_SALE_RETURN])
            ->whereBetween('documents.business_date', [$from, $to])
            ->selectRaw("SUM(CASE WHEN documents.kind = 'sale' THEN document_lines.net_amount ELSE -document_lines.net_amount END) AS net_sales")
            ->selectRaw("SUM(CASE WHEN documents.kind = 'sale' THEN COALESCE(document_lines.total_cost_snapshot,0) ELSE -COALESCE(document_lines.total_cost_snapshot,0) END) AS cogs")
            ->first();

        $netSales = (string) ($row->net_sales ?? '0');
        $cogs = (string) ($row->cogs ?? '0');

        return [
            'net_sales' => $netSales,
            'cogs' => $cogs,
            'gross_margin' => bcsub($netSales, $cogs, 6),
        ];
    }

    /** قيمة المخزون الحالية لكل صنف. */
    public function stockValuation(): array
    {
        return DB::table('stock_balances')
            ->join('items', 'items.id', '=', 'stock_balances.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_balances.warehouse_id')
            ->orderBy('items.name_ar')
            ->get([
                'items.id AS item_id', 'items.sku', 'items.name_ar', 'warehouses.name AS warehouse_name',
                'stock_balances.quantity', 'stock_balances.average_cost', 'stock_balances.inventory_value',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** قيمة المخزون كما كانت في نهاية تاريخ معيّن، بإعادة بنائها من حركات المخزون. */
    public function stockValuationAsOf(string $date): array
    {
        return DB::table('stock_movements')
            ->join('items', 'items.id', '=', 'stock_movements.item_id')
            ->where('stock_movements.occurred_at', '<=', $date.' 23:59:59.999999')
            ->groupBy('items.id', 'items.sku', 'items.name_ar')
            ->havingRaw('SUM(stock_movements.quantity_delta) > 0.000001')
            ->get([
                'items.id AS item_id', 'items.sku', 'items.name_ar',
                DB::raw('SUM(stock_movements.quantity_delta) AS quantity'),
                DB::raw('SUM(stock_movements.value_delta) AS inventory_value'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** حركة صنف واحد بالتفصيل (دفتر الصنف). */
    public function itemLedger(int $itemId, string $from, string $to): array
    {
        return DB::table('stock_movements')
            ->leftJoin('documents', 'documents.id', '=', 'stock_movements.document_id')
            ->where('stock_movements.item_id', $itemId)
            ->whereBetween('stock_movements.occurred_at', [$from.' 00:00:00', $to.' 23:59:59.999999'])
            ->orderBy('stock_movements.occurred_at')
            ->orderBy('stock_movements.id')
            ->get([
                'stock_movements.occurred_at', 'stock_movements.movement_type', 'stock_movements.quantity_delta',
                'stock_movements.value_delta', 'stock_movements.unit_cost', 'stock_movements.balance_after', 'stock_movements.value_after',
                'documents.document_number', 'documents.kind AS document_kind',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** أصناف راكدة: لا بيع ولا استهلاك خلال آخر $days يومًا. */
    public function slowMovingItems(int $days = 60): array
    {
        $since = Carbon::now()->subDays($days)->toDateTimeString();

        return DB::table('items')
            ->join('stock_balances', 'stock_balances.item_id', '=', 'items.id')
            ->where('items.item_type', 'stock')
            ->where('items.is_active', true)
            ->where('stock_balances.quantity', '>', 0)
            ->whereNotExists(function ($query) use ($since) {
                $query->select(DB::raw(1))
                    ->from('stock_movements')
                    ->whereColumn('stock_movements.item_id', 'items.id')
                    ->whereIn('stock_movements.movement_type', ['sale', 'material_usage'])
                    ->where('stock_movements.occurred_at', '>=', $since);
            })
            ->get(['items.id', 'items.sku', 'items.name_ar', 'stock_balances.quantity', 'stock_balances.inventory_value'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** التلف وفروقات الجرد المرحّلة، مصنّفة حسب السبب. */
    public function damageAndCountDifferences(string $from, string $to): array
    {
        return DB::table('stock_movements')
            ->join('documents', 'documents.id', '=', 'stock_movements.document_id')
            ->join('items', 'items.id', '=', 'stock_movements.item_id')
            ->where('documents.kind', Document::KIND_STOCK_ADJUSTMENT)
            ->whereBetween('documents.business_date', [$from, $to])
            ->orderByDesc('documents.business_date')
            ->get([
                'documents.business_date', 'documents.document_number', 'documents.reason',
                'items.sku', 'items.name_ar',
                'stock_movements.quantity_delta', 'stock_movements.value_delta',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** المشتريات مجمّعة حسب المورد. */
    public function purchasesBySupplier(string $from, string $to): array
    {
        return DB::table('documents')
            ->join('parties', 'parties.id', '=', 'documents.party_id')
            ->where('documents.status', 'posted')
            ->whereIn('documents.kind', [Document::KIND_PURCHASE, Document::KIND_PURCHASE_RETURN])
            ->whereBetween('documents.business_date', [$from, $to])
            ->groupBy('parties.id', 'parties.name')
            ->orderByDesc(DB::raw("SUM(CASE WHEN documents.kind = 'purchase' THEN documents.grand_total ELSE -documents.grand_total END)"))
            ->get([
                'parties.id AS party_id', 'parties.name AS party_name',
                DB::raw("SUM(CASE WHEN documents.kind = 'purchase' THEN 1 ELSE 0 END) AS invoices"),
                DB::raw("SUM(CASE WHEN documents.kind = 'purchase' THEN documents.grand_total ELSE -documents.grand_total END) AS net_purchases"),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** أعمار الذمم: مدينة (عملاء) أو دائنة (موردون)، على أساس تاريخ الاستحقاق. */
    public function agingReport(string $direction, string $asOf): array
    {
        [$kind, $dueField] = $direction === 'payable'
            ? [[Document::KIND_PURCHASE], 'due_date']
            : [[Document::KIND_SALE], 'due_date'];

        $rows = DB::table('documents')
            ->join('parties', 'parties.id', '=', 'documents.party_id')
            ->leftJoin(DB::raw('(SELECT document_id, SUM(amount) AS paid FROM payment_allocations GROUP BY document_id) pa'), 'pa.document_id', '=', 'documents.id')
            ->where('documents.status', 'posted')
            ->whereIn('documents.kind', $kind)
            ->whereRaw('documents.grand_total - COALESCE(pa.paid, 0) > 0.005')
            ->get([
                'documents.id', 'documents.document_number', 'parties.id AS party_id', 'parties.name AS party_name',
                'documents.'.$dueField.' AS due_date', 'documents.business_date',
                DB::raw('documents.grand_total - COALESCE(pa.paid, 0) AS outstanding'),
            ]);

        $buckets = ['current' => '0', 'days_1_30' => '0', 'days_31_60' => '0', 'days_61_90' => '0', 'over_90' => '0'];
        $detail = [];

        foreach ($rows as $row) {
            $due = $row->due_date ?? $row->business_date;
            $daysOverdue = Carbon::parse($asOf)->diffInDays(Carbon::parse($due), false);
            $bucket = match (true) {
                $daysOverdue >= 0 => 'current',
                $daysOverdue >= -30 => 'days_1_30',
                $daysOverdue >= -60 => 'days_31_60',
                $daysOverdue >= -90 => 'days_61_90',
                default => 'over_90',
            };

            $buckets[$bucket] = bcadd($buckets[$bucket], (string) $row->outstanding, 6);
            $detail[] = array_merge((array) $row, ['bucket' => $bucket]);
        }

        return ['as_of' => $asOf, 'buckets' => $buckets, 'documents' => $detail];
    }

    /** تسوية الصندوق/البنك/الشبكة: رصيد الأستاذ مقابل صافي الدفعات المرحّلة. */
    public function financialAccountReconciliation(): array
    {
        return DB::table('financial_accounts')
            ->join('accounts', 'accounts.id', '=', 'financial_accounts.ledger_account_id')
            ->leftJoin(DB::raw('(SELECT account_id, SUM(debit - credit) AS net FROM journal_lines
                JOIN journal_entries ON journal_entries.id = journal_lines.journal_entry_id
                WHERE journal_entries.status = "posted" GROUP BY account_id) jl'), 'jl.account_id', '=', 'accounts.id')
            ->get([
                'financial_accounts.id', 'financial_accounts.name', 'financial_accounts.kind',
                DB::raw('COALESCE(jl.net, 0) AS ledger_balance'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** ميزان المراجعة: مجموع مدين ودائن كل حساب من القيود المرحلة فقط. */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->when($from, fn ($q, $d) => $q->where('journal_entries.entry_date', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('journal_entries.entry_date', '<=', $d))
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type')
            ->orderBy('accounts.code')
            ->get([
                'accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type',
                DB::raw('SUM(journal_lines.debit) AS debit'),
                DB::raw('SUM(journal_lines.credit) AS credit'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** دفتر الأستاذ لحساب واحد، برصيد متحرك. */
    public function generalLedger(int $accountId, string $from, string $to): array
    {
        $opening = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_entries.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS net')
            ->value('net');

        $lines = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->leftJoin('documents', 'documents.id', '=', 'journal_entries.document_id')
            ->leftJoin('payments', 'payments.id', '=', 'journal_entries.payment_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_lines.account_id', $accountId)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->get([
                'journal_entries.entry_date', 'journal_entries.description',
                'journal_lines.debit', 'journal_lines.credit',
                'documents.document_number', 'payments.payment_number',
            ]);

        $running = (string) $opening;
        $rows = [];

        foreach ($lines as $line) {
            $running = bcadd($running, bcsub((string) $line->debit, (string) $line->credit, 6), 6);
            $rows[] = array_merge((array) $line, ['balance' => $running]);
        }

        return ['opening_balance' => (string) $opening, 'closing_balance' => $running, 'lines' => $rows];
    }

    /** قائمة الدخل: الإيراد − (تكلفة المبيعات + المصروفات) لفترة محددة. */
    public function profitAndLoss(string $from, string $to): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->whereIn('accounts.account_type', ['revenue', 'expense'])
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type')
            ->get([
                'accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type',
                DB::raw('SUM(journal_lines.credit - journal_lines.debit) AS revenue_net'),
                DB::raw('SUM(journal_lines.debit - journal_lines.credit) AS expense_net'),
            ]);

        $revenue = '0';
        $expense = '0';
        $lines = [];

        foreach ($rows as $row) {
            $amount = $row->account_type === 'revenue' ? (string) $row->revenue_net : (string) $row->expense_net;
            $lines[] = ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'type' => $row->account_type, 'amount' => $amount];

            if ($row->account_type === 'revenue') {
                $revenue = bcadd($revenue, $amount, 6);
            } else {
                $expense = bcadd($expense, $amount, 6);
            }
        }

        return ['from' => $from, 'to' => $to, 'lines' => $lines, 'revenue_total' => $revenue, 'expense_total' => $expense, 'net_profit' => bcsub($revenue, $expense, 6)];
    }

    /** الميزانية العمومية: أصول/خصوم/حقوق ملكية تراكمية حتى تاريخ معيّن. */
    public function balanceSheet(string $asOf): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->whereIn('accounts.account_type', ['asset', 'liability', 'equity'])
            ->where('journal_entries.entry_date', '<=', $asOf)
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type')
            ->get([
                'accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type',
                DB::raw('SUM(journal_lines.debit - journal_lines.credit) AS net'),
            ]);

        $groups = ['asset' => [], 'liability' => [], 'equity' => []];

        foreach ($rows as $row) {
            $net = $row->account_type === 'asset' ? (string) $row->net : bcmul((string) $row->net, '-1', 6);
            $groups[$row->account_type][] = ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'amount' => $net];
        }

        $totalAssets = array_reduce($groups['asset'], fn ($c, $r) => bcadd($c, $r['amount'], 6), '0');
        $totalLiabilities = array_reduce($groups['liability'], fn ($c, $r) => bcadd($c, $r['amount'], 6), '0');
        $totalEquity = array_reduce($groups['equity'], fn ($c, $r) => bcadd($c, $r['amount'], 6), '0');

        return [
            'as_of' => $asOf,
            'assets' => $groups['asset'],
            'liabilities' => $groups['liability'],
            'equity' => $groups['equity'],
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
        ];
    }

    /** ضريبة القيمة المضافة: مخرجات ومدخلات قابلة للخصم خلال فترة، وصافي المستحق. */
    public function vatReport(string $from, string $to): array
    {
        $output = DB::table('document_tax_totals')
            ->join('documents', 'documents.id', '=', 'document_tax_totals.document_id')
            ->where('documents.status', 'posted')
            ->whereIn('documents.kind', [Document::KIND_SALE, Document::KIND_SALE_RETURN])
            ->whereBetween('documents.business_date', [$from, $to])
            ->selectRaw("SUM(CASE WHEN documents.kind = 'sale' THEN document_tax_totals.tax_amount ELSE -document_tax_totals.tax_amount END) AS total")
            ->value('total');

        $input = DB::table('document_lines')
            ->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->where('documents.status', 'posted')
            ->whereIn('documents.kind', [Document::KIND_PURCHASE, Document::KIND_PURCHASE_RETURN, Document::KIND_EXPENSE])
            ->whereBetween('documents.business_date', [$from, $to])
            ->selectRaw("SUM(CASE WHEN documents.kind = 'purchase_return' THEN -document_lines.tax_recoverable_amount ELSE document_lines.tax_recoverable_amount END) AS total")
            ->value('total');

        $output = (string) ($output ?? '0');
        $input = (string) ($input ?? '0');

        return [
            'from' => $from,
            'to' => $to,
            'output_vat' => $output,
            'input_vat_recoverable' => $input,
            'net_due' => bcsub($output, $input, 6),
        ];
    }

    /** حالة مستندات الفوترة الإلكترونية (رقابة، لا محاسبة). */
    public function einvoiceStatus(?string $status = null): array
    {
        return DB::table('einvoice_documents')
            ->join('documents', 'documents.id', '=', 'einvoice_documents.document_id')
            ->when($status, fn ($q, $s) => $q->where('einvoice_documents.status', $s))
            ->orderByDesc('einvoice_documents.id')
            ->get([
                'einvoice_documents.id', 'einvoice_documents.status', 'einvoice_documents.submission_type',
                'einvoice_documents.reporting_due_at', 'documents.document_number', 'documents.grand_total',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
