<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
