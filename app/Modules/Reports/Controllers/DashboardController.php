<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Modules\EInvoicing\Models\EinvoiceDocument;
use App\Modules\Reports\Services\DashboardQueryService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function __construct(private readonly DashboardQueryService $queries) {}

    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'today' => $this->queries->today(),
            'alerts' => [
                'low_stock' => $this->queries->lowStock(),
                'overdue_receivables' => $this->queries->overdueReceivables(),
                'einvoice_problems' => $this->queries->einvoiceProblems(),
            ],
            'einvoice_summary' => EinvoiceDocument::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'open_cash_session' => $this->queries->openCashSession(),
        ]);
    }
}
