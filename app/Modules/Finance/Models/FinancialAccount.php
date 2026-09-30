<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAccount extends Model
{
    protected $table = 'financial_accounts';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ledger_account_id');
    }
}
