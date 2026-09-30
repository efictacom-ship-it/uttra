<?php

declare(strict_types=1);

namespace App\Modules\Finance\Controllers;

use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Services\CashSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashSessionController
{
    public function __construct(
        private readonly CashSessionService $sessions,
    ) {}

    public function index(Request $request): Response
    {
        $sessions = CashSession::query()
            ->with('financialAccount:id,name')
            ->when($request->integer('financial_account_id'), fn ($q, $id) => $q->where('financial_account_id', $id))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Finance/CashSessions/Index', [
            'sessions' => $sessions,
            'financialAccounts' => FinancialAccount::query()->where('kind', 'cash')->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'opening_cash' => ['required', 'numeric', 'gte:0'],
        ]);

        $this->sessions->open((int) $data['financial_account_id'], (string) $data['opening_cash'], (int) $request->user()->id);

        return back()->with('success', 'تم فتح الصندوق.');
    }

    public function close(Request $request, CashSession $cashSession): RedirectResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'gte:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $closed = $this->sessions->close($cashSession->id, (string) $data['counted_cash'], $data['reason'] ?? null, (int) $request->user()->id);

        return back()->with('success', 'تم إغلاق الصندوق. المتوقع '.$closed->expected_cash.' والمعدود '.$closed->counted_cash.'.');
    }
}
