<?php

declare(strict_types=1);

namespace App\Shared\Documents\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTaxTotal extends Model
{
    protected $table = 'document_tax_totals';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'rate' => 'string',
        'taxable_amount' => 'string',
        'tax_amount' => 'string',
    ];
}
