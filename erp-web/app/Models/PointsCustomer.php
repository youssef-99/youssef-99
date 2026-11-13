<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Loyalty points customer (عميل النقاط).
 */
class PointsCustomer extends Model
{
    protected $table = 'points_customers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'points_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PointsTransaction::class, 'points_customer_id');
    }

    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class, 'points_customer_id');
    }

    public function getLabelAttribute(): string
    {
        return "{$this->code} - {$this->name} ({$this->mobile})";
    }
}
