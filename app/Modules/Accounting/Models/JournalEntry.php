<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $table = 'journal_entries';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'entry_date' => 'date',
        'created_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('line_number');
    }
}
