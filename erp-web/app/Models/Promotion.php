<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promotion extends Model
{
    use HasBilingualName;

    protected $table = 'promotions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'buy_qty' => 'decimal:3',
            'free_qty' => 'decimal:3',
            'from_date' => 'date',
            'to_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
