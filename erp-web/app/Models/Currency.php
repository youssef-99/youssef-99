<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasBilingualName;

    protected $table = 'currencies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
            'is_default' => 'boolean',
        ];
    }
}
