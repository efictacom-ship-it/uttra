<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** وصفة خامات لكل وحدة خدمة. الكمية بالوحدة الأساسية للخام. */
class ServiceRecipeLine extends Model
{
    protected $table = 'service_recipe_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'base_quantity' => 'string',
        'is_active' => 'boolean',
    ];

    public function serviceUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'service_item_unit_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'material_item_id');
    }
}
