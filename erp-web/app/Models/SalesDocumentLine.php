<?php

namespace App\Models;

use App\Models\Concerns\ComputesLineAmounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDocumentLine extends Model
{
    use ComputesLineAmounts;

    protected $table = 'sales_document_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'approved_qty' => 'decimal:3',
            'free_qty' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'gross' => 'decimal:2',
            'discount' => 'decimal:2',
            'discount_percent' => 'decimal:4',
            'tax_percent' => 'decimal:4',
            'tax' => 'decimal:2',
            'net' => 'decimal:2',
            'unit_cost' => 'decimal:4',
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

    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    public function drugDose(): BelongsTo
    {
        return $this->belongsTo(DrugDose::class, 'drug_dose_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }
}
