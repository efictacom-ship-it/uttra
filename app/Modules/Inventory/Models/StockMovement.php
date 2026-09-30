<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Item;
use App\Shared\Documents\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $table = 'stock_movements';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity_delta' => 'string',
        'value_delta' => 'string',
        'unit_cost' => 'string',
        'balance_after' => 'string',
        'value_after' => 'string',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
