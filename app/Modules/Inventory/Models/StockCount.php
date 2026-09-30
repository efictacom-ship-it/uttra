<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Shared\Documents\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    protected $table = 'stock_counts';

    public $timestamps = false;

    protected $guarded = ['id', 'active_marker'];

    protected $casts = ['counted_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'stock_count_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function adjustmentDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'adjustment_document_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['draft', 'counting'], true);
    }
}
