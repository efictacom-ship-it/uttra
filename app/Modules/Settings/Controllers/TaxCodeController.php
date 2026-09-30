<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers;

use App\Modules\Settings\Models\TaxCode;
use App\Shared\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * المعالجات الضريبية معرّفة بالكامل من الشاشة: لا نسبة مفروضة على كل السلع والخدمات.
 * category يتبع تصنيفات الفاتورة الإلكترونية (S قياسي، Z صفري، E معفى، O خارج النطاق).
 */
class TaxCodeController
{
    public function __construct(private readonly AuditService $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $taxCode = TaxCode::query()->create($data);
        $this->audit->log('tax_code', $taxCode->id, 'created', null, $data);

        return back()->with('success', 'تمت إضافة المعالجة الضريبية.');
    }

    public function update(Request $request, TaxCode $taxCode): RedirectResponse
    {
        $before = $taxCode->toArray();
        $data = $this->validated($request, $taxCode->id);

        // تغيير النسبة لا يمس أي مستند تاريخي: السطور تحمل لقطة النسبة وقت الإصدار
        $taxCode->update($data);
        $this->audit->log('tax_code', $taxCode->id, 'updated', $before, $data);

        return back()->with('success', 'تم تحديث المعالجة الضريبية.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'in:S,Z,E,O'],
            'rate' => ['required', 'numeric', 'between:0,100'],
            'exemption_reason_code' => ['nullable', 'string', 'max:50'],
            'exemption_reason' => ['nullable', 'string', 'max:255'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['required', 'boolean'],
        ]);

        $exists = TaxCode::query()->where('code', $data['code'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

        if ($exists) {
            throw ValidationException::withMessages(['code' => 'كود المعالجة الضريبية مستخدم.']);
        }

        if ($data['category'] !== 'S' && (float) $data['rate'] !== 0.0) {
            throw ValidationException::withMessages(['rate' => 'التصنيفات الصفرية والمعفاة وخارج النطاق نسبتها صفر.']);
        }

        if (in_array($data['category'], ['Z', 'E', 'O'], true) && blank($data['exemption_reason'])) {
            throw ValidationException::withMessages(['exemption_reason' => 'سبب المعالجة الصفرية أو الإعفاء مطلوب.']);
        }

        return $data;
    }
}
