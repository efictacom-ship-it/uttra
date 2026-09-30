<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Models;

use Illuminate\Database\Eloquent\Model;

/** وحدة الإصدار. الشهادات والمفاتيح في مخزن أسرار؛ هنا المرجع فقط. */
class EinvoiceDevice extends Model
{
    protected $table = 'einvoice_devices';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['credentials_secret_ref'];

    protected $casts = [
        'certificate_expires_at' => 'datetime',
        'next_invoice_counter' => 'integer',
        'is_active' => 'boolean',
    ];
}
