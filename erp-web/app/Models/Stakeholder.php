<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class Stakeholder extends Model
{
    use HasBilingualName;

    protected $table = 'stakeholders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'share_percent' => 'decimal:4',
        ];
    }
}
