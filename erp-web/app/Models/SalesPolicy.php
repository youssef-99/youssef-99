<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesPolicy extends Model
{
    use HasBilingualName;

    protected $table = 'sales_policies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:4',
            'markup_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
