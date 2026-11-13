<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class DrugDose extends Model
{
    use HasBilingualName;

    protected $table = 'drug_doses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
        ];
    }
}
