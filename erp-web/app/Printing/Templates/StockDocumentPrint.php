<?php

namespace App\Printing\Templates;

use App\Enums\StockDocumentType;
use App\Models\StockDocument;
use App\Models\StockDocumentLine;
use App\Printing\PrintTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Stock adjustments, transfers and stocktake records.
 */
class StockDocumentPrint extends PrintTemplate
{
    public function find(int $id): Model
    {
        return StockDocument::query()
            ->with(['branch.company', 'warehouse', 'toBranch', 'toWarehouse', 'account', 'supplier', 'salesman', 'relatedDocument', 'lines.item', 'lines.unit', 'lines.itemBatch'])
            ->findOrFail($id);
    }

    public function build(Model $record, Request $request): array
    {
        /** @var StockDocument $record */
        $type = StockDocumentType::tryFrom((string) $record->type);
        $isStocktake = (bool) $type?->isStocktake();

        $columns = [
            'no' => ['label' => __('No.'), 'align' => 'start'],
            'code' => ['label' => __('Item Code'), 'align' => 'start'],
            'name' => ['label' => __('Item Name'), 'align' => 'start'],
            'unit' => ['label' => __('Unit'), 'align' => 'start'],
            'batch' => ['label' => __('Batch No.'), 'align' => 'start'],
            'expiry' => ['label' => __('Expiry Date'), 'align' => 'start'],
        ];

        if ($isStocktake) {
            $columns += [
                'system_qty' => ['label' => __('Book Quantity'), 'align' => 'end'],
                'quantity' => ['label' => __('Counted Quantity'), 'align' => 'end'],
                'difference' => ['label' => __('Difference'), 'align' => 'end'],
            ];
        } else {
            $columns['quantity'] = ['label' => __('Quantity'), 'align' => 'end'];
        }

        $columns += [
            'price' => ['label' => __('Price'), 'align' => 'end'],
            'net' => ['label' => __('Value'), 'align' => 'end'],
        ];

        $rows = $record->lines->values()->map(fn (StockDocumentLine $line, int $index): array => [
            'no' => $index + 1,
            'code' => $line->item?->code,
            'name' => $this->itemName($line->item),
            'unit' => $line->unit?->name,
            'batch' => $line->batch_no ?: $line->itemBatch?->batch_no,
            'expiry' => static::date($line->expiry_date ?? $line->itemBatch?->expiry_date),
            'system_qty' => static::quantity($line->system_qty),
            'quantity' => static::quantity($line->quantity),
            'difference' => static::quantity((float) $line->quantity - (float) $line->system_qty),
            'price' => static::money($line->price),
            'net' => static::money($line->net),
        ])->all();

        return [
            'title' => $type?->label() ?? __('Stock Document'),
            'subtitle' => null,
            'status' => $this->statusLabel($record),
            'number' => $record->number,
            'fields' => $this->fields([
                ['Document No.', $record->number],
                ['Date', static::date($record->date)],
                ['Branch', $record->branch?->name],
                ['Warehouse', $record->warehouse?->name],
                ['To Branch', $record->toBranch?->name],
                ['To Warehouse', $record->toWarehouse?->name],
                ['Account', $record->account ? $record->account->number.' - '.$record->account->name : null],
                ['Supplier', $this->partyName($record->supplier)],
                ['Salesman', $record->salesman?->name],
                ['Related Document No.', $record->relatedDocument?->number],
                ['Approval Status', $isStocktake ? ($record->is_approved ? __('Approved Document') : __('Not Approved')) : null],
            ]),
            'partyTitle' => null,
            'partyFields' => [],
            'columns' => $columns,
            'rows' => $rows,
            'footerRow' => [
                'name' => __('Total'),
                'quantity' => static::quantity($record->lines->sum('quantity')),
                'net' => static::money($record->lines->sum('net')),
            ],
            'totals' => [
                [__('Total'), static::money($record->net_total ?: $record->lines->sum('net')), true],
            ],
            'grandTotal' => null,
            'amountInWords' => null,
            'notes' => $record->notes,
            'qr' => null,
            'signatures' => [__('Prepared By'), __('Storekeeper'), __('Approved By')],
            'record' => $record,
        ];
    }
}
