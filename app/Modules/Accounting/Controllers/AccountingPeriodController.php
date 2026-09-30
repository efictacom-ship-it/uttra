<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Services\PeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** إقفال الفترات المحاسبية: بعد الإقفال لا يُرحَّل أي مستند أو دفعة بتاريخ ضمن الفترة. */
class AccountingPeriodController
{
    public function __construct(
        private readonly PeriodService $periods,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Accounting/Periods/Index', [
            'periods' => AccountingPeriod::query()->orderByDesc('starts_on')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $period = $this->periods->createYear((int) $data['year'], (int) ($data['start_month'] ?? 1));

        return back()->with('success', 'تم إنشاء الفترة «'.$period->name.'».');
    }

    public function close(Request $request, AccountingPeriod $period): RedirectResponse
    {
        $this->periods->close($period, (int) $request->user()->id);

        return back()->with('success', 'تم إقفال الفترة «'.$period->name.'»؛ لا يمكن الترحيل فيها بعد الآن.');
    }

    public function reopen(AccountingPeriod $period): RedirectResponse
    {
        $this->periods->reopen($period);

        return back()->with('success', 'تم إعادة فتح الفترة «'.$period->name.'».');
    }
}
