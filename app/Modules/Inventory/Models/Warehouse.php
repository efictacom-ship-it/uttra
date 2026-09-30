<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $table = 'warehouses';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public static function primary(): ?self
    {
        return static::query()->where('is_active', true)->orderBy('id')->first();
    }
}
