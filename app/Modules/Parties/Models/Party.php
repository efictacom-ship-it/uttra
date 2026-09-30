<?php

declare(strict_types=1);

namespace App\Modules\Parties\Models;

use Illuminate\Database\Eloquent\Model;

class Party extends Model
{
    protected $table = 'parties';

    protected $guarded = ['id'];

    protected $casts = [
        'is_customer' => 'boolean',
        'is_supplier' => 'boolean',
        'address_json' => 'array',
        'credit_limit' => 'string',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
