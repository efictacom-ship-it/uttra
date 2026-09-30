<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ربط حسابات التشغيل بدليل الحسابات. لا يُسمح بأرقام حسابات ثابتة في الكود:
 * كل خدمة تطلب الحساب بالـ slug من هذا الجدول.
 */
class AccountMapping extends Model
{
    protected $table = 'account_mappings';

    const CREATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'is_required' => 'boolean',
        'updated_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
