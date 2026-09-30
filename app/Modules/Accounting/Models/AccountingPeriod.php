<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $table = 'accounting_periods';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'closed_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
