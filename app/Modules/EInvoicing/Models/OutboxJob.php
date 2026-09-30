<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Models;

use Illuminate\Database\Eloquent\Model;

/** مهمة تُكتب داخل معاملة الترحيل نفسها، وتُنفّذ خارجها (outbox pattern). */
class OutboxJob extends Model
{
    protected $table = 'outbox_jobs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'payload_json' => 'array',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'locked_at' => 'datetime',
    ];
}
