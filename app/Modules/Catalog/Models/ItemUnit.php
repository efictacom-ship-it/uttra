<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemUnit extends Model
{
    protected $table = 'item_units';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'to_base_factor' => 'string',
        'sale_price' => 'string',
        'price_includes_tax' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class, 'item_unit_id');
    }

    public function recipeLines(): HasMany
    {
        return $this->hasMany(ServiceRecipeLine::class, 'service_item_unit_id');
    }
}
