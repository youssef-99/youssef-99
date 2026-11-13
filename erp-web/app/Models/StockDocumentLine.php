<?php

namespace App\Models;

use App\Models\Concerns\ComputesLineAmounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockDocumentLine extends Model
{
    use ComputesLineAmounts;

    protected $table = 'stock_document_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'system_qty' => 'decimal:3',
            'quantity' => 'decimal:3',
            'price' => 'decimal:4',
            'gross' => 'decimal:2',
            'discount' => 'decimal:2',
            'discount_percent' => 'decimal:4',
            'net' => 'decimal:2',
        ];
    }

    public function itemBatch(): BelongsTo
    {
        return $this->belongsTo(ItemBatch::class, 'item_batch_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function stockDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }
}
