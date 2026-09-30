<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'created_at' => 'datetime',
    ];
}
