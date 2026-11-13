<?php

namespace App\Printing\Templates;

use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Goods receipt note (إذن إدخال بضاعة) for a purchase invoice: items, quantity, bonus, batch, expiry; no prices.
 */
class GoodsReceiptNotePrint extends PurchaseDocumentPrint
{
    public function build(Model $record, Request $request): array
    {
        /** @var PurchaseDocument $record */
        $data = parent::build($record, $request);
        $supplier = $record->supplier ?? $record->cashSupplier;

        $rows = $record->lines->values()->map(fn (PurchaseDocumentLine $line, int $index): array => [
            'no' => $index + 1,
            'code' => $line->item?->code,
            'barcode' => $line->item?->barcode,
            'name' => $this->itemName($line->item),
            'unit' => $line->unit?->name,
            'quantity' => static::quantity($line->quantity),
            'bonus' => static::quantity($line->bonus_qty),
            'total_quantity' => static::quantity((float) $line->quantity + (float) $line->bonus_qty),
            'batch' => $line->batch_no,
            'expiry' => static::date($line->expiry_date),
        ])->all();

        return [
            ...$data,
            'title' => __('Goods Receipt Note'),
            'subtitle' => $data['title'],
            'fields' => $this->fields([
                ['Invoice No.', $record->number],
                ['Invoice Date', static::date($record->date)],
                ['Receipt Date', static::date($record->receipt_date)],
                ['Branch', $record->branch?->name],
                ['Warehouse', $record->warehouse?->name],
                ['Supplier Invoice No.', $record->supplier_invoice_no],
            ]),
            'partyFields' => $this->fields([
                ['Supplier', $this->partyName($supplier)],
            ]),
            'columns' => [
                'no' => ['label' => __('No.'), 'align' => 'start'],
                'code' => ['label' => __('Item Code'), 'align' => 'start'],
                'barcode' => ['label' => __('Barcode'), 'align' => 'start'],
                'name' => ['label' => __('Item Name'), 'align' => 'start'],
                'unit' => ['label' => __('Unit'), 'align' => 'start'],
                'quantity' => ['label' => __('Quantity'), 'align' => 'end'],
                'bonus' => ['label' => __('Bonus'), 'align' => 'end'],
                'total_quantity' => ['label' => __('Total Quantity'), 'align' => 'end'],
                'batch' => ['label' => __('Batch No.'), 'align' => 'start'],
                'expiry' => ['label' => __('Expiry Date'), 'align' => 'start'],
            ],
            'rows' => $rows,
            'footerRow' => [
                'name' => __('Total'),
                'quantity' => static::quantity($record->lines->sum('quantity')),
                'bonus' => static::quantity($record->lines->sum('bonus_qty')),
                'total_quantity' => static::quantity((float) $record->lines->sum('quantity') + (float) $record->lines->sum('bonus_qty')),
            ],
            'totals' => [],
            'grandTotal' => null,
            'amountInWords' => null,
            'signatures' => [__('Delivered By'), __('Storekeeper'), __('Approved By')],
        ];
    }
}
