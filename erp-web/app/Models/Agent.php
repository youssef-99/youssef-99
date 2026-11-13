<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use HasBilingualName;

    protected $table = 'agents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
        ];
    }
}
