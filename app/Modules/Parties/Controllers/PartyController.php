<?php

declare(strict_types=1);

namespace App\Modules\Parties\Controllers;

use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Services\PartyStatementService;
use App\Shared\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PartyController
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PartyStatementService $statements,
    ) {}

    public function index(Request $request): Response
    {
        $parties = Party::query()
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('vat_number', 'like', "%{$s}%")))
            ->when($request->string('role')->toString() === 'customer', fn ($q) => $q->where('is_customer', true))
            ->when($request->string('role')->toString() === 'supplier', fn ($q) => $q->where('is_supplier', true))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Parties/Index', [
            'parties' => $parties,
            'filters' => $request->only('q', 'role'),
            'balances' => $this->statements->balancesFor($parties->pluck('id')->all()),
        ]);
    }

    public function show(Request $request, Party $party): Response
    {
        return Inertia::render('Parties/Statement', [
            'party' => $party,
            'statement' => $this->statements->statement(
                $party,
                $request->string('from')->toString() ?: now('Asia/Riyadh')->startOfYear()->toDateString(),
                $request->string('to')->toString() ?: now('Asia/Riyadh')->toDateString(),
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $party = Party::query()->create($data);
        $this->audit->log('party', $party->id, 'created', null, $data);

        return back()->with('success', 'تمت إضافة الطرف.');
    }

    public function update(Request $request, Party $party): RedirectResponse
    {
        $before = $party->toArray();
        $data = $this->validated($request, $party->id);

        $party->update($data);
        $this->audit->log('party', $party->id, 'updated', $before, $data);

        return back()->with('success', 'تم تحديث بيانات الطرف.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('parties', 'name')->ignore($ignoreId)],
            'is_customer' => ['required', 'boolean'],
            'is_supplier' => ['required', 'boolean'],
            'phone' => ['nullable', 'string', 'max:40'],
            'vat_number' => ['nullable', 'string', 'regex:/^3\d{13}3$/'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'credit_limit' => ['nullable', 'numeric', 'gte:0'],
            'is_active' => ['required', 'boolean'],
            'address_json' => ['nullable', 'array'],
        ], [
            'vat_number.regex' => 'الرقم الضريبي يجب أن يكون 15 رقمًا يبدأ وينتهي بالرقم 3.',
            'name.unique' => 'يوجد طرف بنفس الاسم.',
        ]);

        if (! $data['is_customer'] && ! $data['is_supplier']) {
            abort(422, 'الطرف يجب أن يكون عميلًا أو موردًا أو كليهما.');
        }

        return $data;
    }
}
