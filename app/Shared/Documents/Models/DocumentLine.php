<?php

declare(strict_types=1);

namespace App\Shared\Documents\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطر مستند: يجمّد الاسم والوحدة والمعامل والسعر والضريبة والتكلفة وقت الإصدار. */
class DocumentLine extends Model
{
    protected $table = 'document_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'string',
        'to_base_factor' => 'string',
        'base_quantity' => 'string',
        'unit_price' => 'string',
        'entered_unit_price' => 'string',
        'discount_amount' => 'string',
        'tax_rate_snapshot' => 'string',
        'net_amount' => 'string',
        'tax_amount' => 'string',
        'gross_amount' => 'string',
        'tax_recoverable_amount' => 'string',
        'unit_cost_snapshot' => 'string',
        'total_cost_snapshot' => 'string',
        'price_includes_tax_snapshot' => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'item_unit_id');
    }

    public function originalLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_line_id');
    }
}
