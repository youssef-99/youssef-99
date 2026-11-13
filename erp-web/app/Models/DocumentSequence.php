<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    protected $table = 'document_sequences';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
        ];
    }
}
