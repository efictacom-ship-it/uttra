<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    protected $table = 'stock_count_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'expected_quantity' => 'string',
        'counted_quantity' => 'string',
        'unit_cost_snapshot' => 'string',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
