<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class CustomerGroup extends Model
{
    use HasBilingualName;

    protected $table = 'customer_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
        ];
    }
}
