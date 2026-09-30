<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Parties\Models\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $table = 'payments';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'string',
        'fee_amount' => 'string',
        'payment_date' => 'date',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_financial_account_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }
}
