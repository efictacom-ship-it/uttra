<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemBarcode extends Model
{
    protected $table = 'item_barcodes';

    public $timestamps = false;

    protected $guarded = ['id'];

    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'item_unit_id');
    }
}
