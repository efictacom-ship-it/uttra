<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/** رصيد مجمّع للأداء. الدفتر الأساسي هو stock_movements. */
class StockBalance extends Model
{
    protected $table = 'stock_balances';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'string',
        'inventory_value' => 'string',
        'average_cost' => 'string',
        'version' => 'integer',
    ];
}
