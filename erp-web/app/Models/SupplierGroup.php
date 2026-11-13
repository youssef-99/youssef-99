<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierGroup extends Model
{
    use HasBilingualName;

    protected $table = 'supplier_groups';

    protected $guarded = ['id'];

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'supplier_group_id');
    }
}
