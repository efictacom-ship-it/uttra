<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    protected $table = 'stock_reservations';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'string',
        'created_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
