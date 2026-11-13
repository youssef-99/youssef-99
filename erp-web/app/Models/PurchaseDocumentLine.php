<?php

namespace App\Models;

use App\Models\Concerns\ComputesLineAmounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseDocumentLine extends Model
{
    use ComputesLineAmounts;

    protected $table = 'purchase_document_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'gross' => 'decimal:2',
            'discount' => 'decimal:2',
            'discount_percent' => 'decimal:4',
            'discount2' => 'decimal:2',
            'discount2_percent' => 'decimal:4',
            'bonus_qty' => 'decimal:3',
            'bonus_percent' => 'decimal:4',
            'tax_percent' => 'decimal:4',
            'tax' => 'decimal:2',
            'unit_net' => 'decimal:4',
            'net' => 'decimal:2',
            'sale_price' => 'decimal:4',
            'serial_numbers' => 'array',
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

    public function purchaseDocument(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocument::class, 'purchase_document_id');
    }
}
