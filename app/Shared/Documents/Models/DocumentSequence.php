<?php

declare(strict_types=1);

namespace App\Shared\Documents\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    protected $table = 'document_sequences';

    public $timestamps = false;

    protected $guarded = ['id'];
}
