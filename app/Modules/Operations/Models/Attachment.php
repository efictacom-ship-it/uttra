<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $table = 'attachments';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'size_bytes' => 'integer',
        'uploaded_at' => 'datetime',
    ];
}
