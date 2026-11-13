<?php

namespace App\Printing\Templates;

use App\Enums\PurchaseDocumentType;
use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentLine;
use App\Printing\AmountInWords;
use App\Printing\PrintTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Purchase orders, purchase / foreign / cash purchase invoices and returns.
 */
class PurchaseDocumentPrint extends PrintTemplate
{
    public function find(int $id): Model
    {
        return PurchaseDocument::query()
            ->with(['branch.company', 'warehouse', 'supplier', 'cashSupplier', 'currency', 'originalDocument', 'purchaseOrder', 'lines.item', 'lines.unit', 'creator'])
            ->findOrFail($id);
    }

    public function build(Model $record, Request $request): array
    {
        /** @var PurchaseDocument $record */
        $type = PurchaseDocumentType::tryFrom((string) $record->type);
        $isForeign = $type === PurchaseDocumentType::ForeignPurchaseInvoice;

        $columns = [
            'no' => ['label' => __('No.'), 'align' => 'start'],
            'code' => ['label' => __('Item Code'), 'align' => 'start'],
            'name' => ['label' => __('Item Name'), 'align' => 'start'],
            'unit' => ['label' => __('Unit'), 'align' => 'start'],
            'batch' => ['label' => __('Batch No. / Expiry Date'), 'align' => 'start'],
            'quantity' => ['label' => __('Quantity'), 'align' => 'end'],
            'bonus' => ['label' => __('Bonus'), 'align' => 'end'],
            'price' => ['label' => __('Item Price'), 'align' => 'end'],
            'gross' => ['label' => __('Gross Price'), 'align' => 'end'],
            'discount' => ['label' => __('Discount'), 'align' => 'end'],
            'tax_percent' => ['label' => __('Tax %'), 'align' => 'end'],
            'tax' => ['label' => __('Tax'), 'align' => 'end'],
            'net' => ['label' => __('Item Net'), 'align' => 'end'],
        ];

        $rows = $record->lines->values()->map(fn (PurchaseDocumentLine $line, int $index): array => [
            'no' => $index + 1,
            'code' => $line->item?->code,
            'name' => $this->itemName($line->item),
            'unit' => $line->unit?->name,
            'batch' => trim($line->batch_no.' / '.static::date($line->expiry_date), ' /'),
            'quantity' => static::quantity($line->quantity),
            'bonus' => static::quantity($line->bonus_qty),
            'price' => static::money($line->unit_price),
            'gross' => static::money($line->gross),
            'discount' => static::money((float) $line->discount + (float) $line->discount2),
            'tax_percent' => static::percent($line->tax_percent),
            'tax' => static::money($line->tax),
            'net' => static::money($line->net),
        ])->all();

        $supplier = $record->supplier ?? $record->cashSupplier;
        $currencyName = $isForeign ? $record->currency?->name : null;

        return [
            'title' => $type?->label() ?? __('Purchase Invoice'),
            'subtitle' => null,
            'status' => $type?->isPostable() ? $this->statusLabel($record) : null,
            'number' => $record->number,
            'fields' => $this->fields([
                ['Invoice No.', $record->number],
                ['Invoice Date', static::date($record->date)],
                ['Branch', $record->branch?->name],
                ['Warehouse', $record->warehouse?->name],
                ['Supplier Invoice No.', $record->supplier_invoice_no],
                ['Receipt Date', static::date($record->receipt_date)],
                ['Payment Period', $record->payment_period_days ? $record->payment_period_days.' '.__('days') : null],
                ['Due Date', static::date($record->due_date)],
                ['Currency', $currencyName],
                ['Exchange Rate', $isForeign ? static::quantity($record->exchange_rate) : null],
                ['Purchase Order No.', $record->purchaseOrder?->number],
                ['Original Invoice No.', $record->originalDocument?->number],
            ]),
            'partyTitle' => __('Supplier'),
            'partyFields' => $this->fields([
                ['Supplier', $this->partyName($supplier)],
                ['VAT No.', $supplier?->vat_no],
                ['Address', $supplier?->address],
                ['Phone', $supplier?->phone ?? $supplier?->mobile],
            ]),
            'columns' => $columns,
            'rows' => $rows,
            'footerRow' => null,
            'totals' => [
                [__('Gross Price'), static::money($record->gross_total), false],
                [__('Item Discounts'), static::money($record->items_discount), false],
                [__('Additional Discount'), static::money($record->additional_discount), false],
                [__('Total Excl. VAT'), static::money($record->net_total), false],
                [__('Total VAT'), static::money($record->tax_total), false],
                [__('Total Incl. VAT'), static::money($record->grand_total), true],
            ],
            'grandTotal' => static::money($record->grand_total),
            'amountInWords' => AmountInWords::format((float) $record->grand_total, currency: $currencyName),
            'notes' => $record->notes,
            'qr' => null,
            'signatures' => [__('Prepared By'), __('Storekeeper'), __('Approved By')],
            'record' => $record,
        ];
    }
}
