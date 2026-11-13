<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasBilingualName;

    protected $table = 'units';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
        ];
    }
}
