<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Models;

use Illuminate\Database\Eloquent\Model;

class EinvoiceSubmission extends Model
{
    protected $table = 'einvoice_submissions';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'response_json' => 'array',
        'attempt_number' => 'integer',
    ];
}
