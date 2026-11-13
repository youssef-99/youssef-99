<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasBilingualName;

    protected $table = 'activities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
        ];
    }
}
