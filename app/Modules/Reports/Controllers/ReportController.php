<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Modules\Reports\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * شاشات التقارير: كل تقرير بمسار خاص، وفلترة بمدى تاريخ يُطبَّق افتراضيًا على
 * الشهر الحالي إن لم يُحدَّد. الطباعة والتصدير على الواجهة (window.print / CSV
 * من نفس البيانات)، فلا حاجة لمسار خادم منفصل لكل صيغة.
 */
class ReportController
{
    public function __construct(private readonly ReportService $reports) {}

    /** قائمة اختيار التقرير: كل تقرير رابط مستقل بفلترة تاريخ خاصة به. */
    public function index(): Response
    {
        return Inertia::render('Reports/Index');
    }

    public function sales(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $groupBy = $request->string('group_by')->toString() ?: 'day';

        return Inertia::render('Reports/Sales', [
            'range' => ['from' => $from, 'to' => $to, 'group_by' => $groupBy],
            'summary' => $this->reports->salesSummary($from, $to, $groupBy),
            'byItem' => $this->reports->salesByItem($from, $to),
            'byCategory' => $this->reports->salesByCategory($from, $to),
            'collections' => $this->reports->collectionsByMethod($from, $to),
            'margin' => $this->reports->costOfGoodsSoldAndMargin($from, $to),
        ]);
    }

    public function inventory(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $asOf = $request->string('as_of')->toString();

        return Inertia::render('Reports/Inventory', [
            'range' => ['from' => $from, 'to' => $to],
            'valuation' => $this->reports->stockValuation(),
            'valuationAsOf' => $asOf ? $this->reports->stockValuationAsOf($asOf) : null,
            'slowMoving' => $this->reports->slowMovingItems((int) $request->integer('days', 60)),
            'damageAndCounts' => $this->reports->damageAndCountDifferences($from, $to),
        ]);
    }

    public function itemLedger(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $itemId = $request->integer('item_id');

        return Inertia::render('Reports/ItemLedger', [
            'range' => ['from' => $from, 'to' => $to],
            'item_id' => $itemId,
            'ledger' => $itemId ? $this->reports->itemLedger($itemId, $from, $to) : [],
        ]);
    }

    public function purchases(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/Purchases', [
            'range' => ['from' => $from, 'to' => $to],
            'bySupplier' => $this->reports->purchasesBySupplier($from, $to),
        ]);
    }

    public function aging(Request $request): Response
    {
        $direction = $request->string('direction')->toString() ?: 'receivable';
        $asOf = $request->string('as_of')->toString() ?: Carbon::today()->toDateString();

        return Inertia::render('Reports/Aging', [
            'direction' => $direction,
            'report' => $this->reports->agingReport($direction, $asOf),
        ]);
    }

    public function reconciliation(): Response
    {
        return Inertia::render('Reports/Reconciliation', [
            'accounts' => $this->reports->financialAccountReconciliation(),
        ]);
    }

    public function trialBalance(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/TrialBalance', [
            'range' => ['from' => $from, 'to' => $to],
            'rows' => $this->reports->trialBalance($from, $to),
        ]);
    }

    public function generalLedger(Request $request): Response
    {
        [$from, $to] = $this->range($request);
        $accountId = (int) $request->integer('account_id');

        return Inertia::render('Reports/GeneralLedger', [
            'range' => ['from' => $from, 'to' => $to],
            'account_id' => $accountId,
            'ledger' => $accountId ? $this->reports->generalLedger($accountId, $from, $to) : null,
            'accounts' => \App\Modules\Accounting\Models\Account::query()->where('allow_posting', true)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function profitAndLoss(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/ProfitAndLoss', [
            'range' => ['from' => $from, 'to' => $to],
            'report' => $this->reports->profitAndLoss($from, $to),
        ]);
    }

    public function balanceSheet(Request $request): Response
    {
        $asOf = $request->string('as_of')->toString() ?: Carbon::today()->toDateString();

        return Inertia::render('Reports/BalanceSheet', [
            'as_of' => $asOf,
            'report' => $this->reports->balanceSheet($asOf),
        ]);
    }

    public function vat(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('Reports/Vat', [
            'range' => ['from' => $from, 'to' => $to],
            'report' => $this->reports->vatReport($from, $to),
        ]);
    }

    public function einvoiceStatus(Request $request): Response
    {
        return Inertia::render('Reports/EinvoiceStatus', [
            'status' => $request->string('status')->toString(),
            'documents' => $this->reports->einvoiceStatus($request->string('status')->toString() ?: null),
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        $from = $request->string('from')->toString() ?: Carbon::today()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: Carbon::today()->toDateString();

        return [$from, $to];
    }
}
