<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAct('manage-catalog');
    }

    public function rules(): array
    {
        $itemId = $this->route('item')?->id;

        return [
            'sku' => ['required', 'string', 'max:60', Rule::unique('items', 'sku')->ignore($itemId)],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'item_type' => ['required', 'in:stock,service'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'tax_code_id' => ['nullable', 'integer', 'exists:tax_codes,id'],
            'reorder_level' => ['nullable', 'numeric', 'gte:0'],
            'revenue_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'inventory_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'cost_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'is_active' => ['required', 'boolean'],
            'units' => ['required', 'array', 'min:1'],
            'units.*.unit_id' => ['required', 'integer', 'exists:units,id'],
            'units.*.to_base_factor' => ['required', 'numeric', 'gt:0'],
            'units.*.sale_price' => ['required', 'numeric', 'gte:0'],
            'units.*.price_includes_tax' => ['required', 'boolean'],
            'units.*.is_active' => ['nullable', 'boolean'],
            'units.*.barcodes' => ['nullable', 'array'],
            'units.*.barcodes.*' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'sku' => 'رمز الصنف',
            'name_ar' => 'الاسم العربي',
            'base_unit_id' => 'الوحدة الأساسية',
            'units' => 'وحدات الصنف',
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'رمز الصنف مستخدم لصنف آخر.',
            'units.*.to_base_factor.gt' => 'معامل التحويل يجب أن يكون أكبر من صفر.',
        ];
    }

    public function payload(): array
    {
        return $this->validated();
    }
}
