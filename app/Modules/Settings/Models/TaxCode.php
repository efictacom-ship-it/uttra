<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class TaxCode extends Model
{
    protected $table = 'tax_codes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'rate' => 'string',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function isValidOn(string $date): bool
    {
        if ($this->valid_from->toDateString() > $date) {
            return false;
        }

        return $this->valid_to === null || $this->valid_to->toDateString() >= $date;
    }
}
