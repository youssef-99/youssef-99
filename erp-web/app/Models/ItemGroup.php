<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class ItemGroup extends Model
{
    use HasBilingualName;

    protected $table = 'item_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
        ];
    }
}
