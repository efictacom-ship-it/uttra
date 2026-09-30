<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Shared\Documents\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    protected $table = 'payment_allocations';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'string'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
