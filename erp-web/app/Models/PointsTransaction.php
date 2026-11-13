<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointsTransaction extends Model
{
    protected $table = 'points_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'points' => 'decimal:2',
        ];
    }

    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    public function pointsCustomer(): BelongsTo
    {
        return $this->belongsTo(PointsCustomer::class, 'points_customer_id');
    }
}
