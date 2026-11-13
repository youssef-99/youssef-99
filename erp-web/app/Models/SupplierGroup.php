<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;

class SupplierGroup extends Model
{
    use HasBilingualName;

    protected $table = 'supplier_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
        ];
    }
}
