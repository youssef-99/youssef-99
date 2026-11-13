<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTransfer extends Model
{
    protected $table = 'customer_transfers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'customer_ids' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toSalesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'to_salesman_id');
    }

    public function fromSalesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'from_salesman_id');
    }
}
