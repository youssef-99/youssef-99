<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemBalanceLimit extends Model
{
    protected $table = 'item_balance_limits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_qty' => 'decimal:3',
            'max_qty' => 'decimal:3',
            'reorder_qty' => 'decimal:3',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
