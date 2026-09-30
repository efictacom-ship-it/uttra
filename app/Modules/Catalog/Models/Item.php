<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Settings\Models\TaxCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    protected $table = 'items';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'reorder_level' => 'string',
        'last_purchase_cost' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function units(): HasMany
    {
        return $this->hasMany(ItemUnit::class, 'item_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }

    public function isStock(): bool
    {
        return $this->item_type === 'stock';
    }

    public function isService(): bool
    {
        return $this->item_type === 'service';
    }
}
