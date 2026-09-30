<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashSession extends Model
{
    protected $table = 'cash_sessions';

    public $timestamps = false;

    protected $guarded = ['id', 'open_marker'];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_cash' => 'string',
        'expected_cash' => 'string',
        'counted_cash' => 'string',
    ];

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
