<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Models;

use App\Shared\Documents\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EinvoiceDocument extends Model
{
    protected $table = 'einvoice_documents';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'invoice_counter' => 'integer',
        'generated_at' => 'datetime',
        'reporting_due_at' => 'datetime',
        'last_submitted_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(EinvoiceDevice::class, 'device_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(EinvoiceSubmission::class, 'einvoice_document_id')->orderBy('attempt_number');
    }
}
