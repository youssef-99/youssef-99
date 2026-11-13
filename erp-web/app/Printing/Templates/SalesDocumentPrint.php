<?php

namespace App\Printing\Templates;

use App\Enums\SalesDocumentType;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Printing\AmountInWords;
use App\Printing\PrintTemplate;
use App\Services\Zatca\ZatcaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

/**
 * Every sales document type: quotations, customer orders, invoices, returns, POS and cash sales.
 * `?layout=80mm` renders the thermal receipt layout.
 */
class SalesDocumentPrint extends PrintTemplate
{
    public function find(int $id): Model
    {
        return SalesDocument::query()
            ->with(['branch.company', 'warehouse', 'customer', 'cashCustomer', 'pointsCustomer', 'salesman', 'doctor', 'originalDocument', 'lines.item', 'lines.unit', 'lines.itemBatch', 'creator'])
            ->findOrFail($id);
    }

    public function view(Model $record, Request $request): string
    {
        return $request->query('layout') === '80mm' ? 'print.receipt-80mm' : 'print.document';
    }

    /**
     * ZATCA document title: Tax Invoice (standard / B2B), Simplified Tax Invoice (B2C), Credit Note (returns).
     */
    public static function title(SalesDocument $doc): string
    {
        $type = SalesDocumentType::tryFrom((string) $doc->type);

        return match (true) {
            $type === null => __('Sales Invoice'),
            ! $type->isPostable() => $type->label(),
            $type->isReturn() => __('Credit Note'),
            static::zatcaType($doc) === 'standard' => __('Tax Invoice'),
            default => __('Simplified Tax Invoice'),
        };
    }

    /** standard (buyer has a VAT number) or simplified, as ZatcaService decides it. */
    public static function zatcaType(SalesDocument $doc): string
    {
        if (filled($doc->zatca_invoice_type)) {
            return $doc->zatca_invoice_type;
        }

        return filled($doc->customer?->vat_no ?? $doc->cashCustomer?->vat_no) ? 'standard' : 'simplified';
    }

    /** Stored Base64 TLV QR, or one generated on the fly for posted ZATCA documents. */
    public static function qr(SalesDocument $doc): ?string
    {
        $type = SalesDocumentType::tryFrom((string) $doc->type);

        if (! $type?->isZatca()) {
            return null;
        }

        if ($doc->is_posted && filled($doc->zatca_qr)) {
            return $doc->zatca_qr;
        }

        if (! $doc->is_posted) {
            return null;
        }

        try {
            return app(ZatcaService::class)->qr($doc);
        } catch (Throwable) {
            return null;
        }
    }

    public function build(Model $record, Request $request): array
    {
        /** @var SalesDocument $record */
        $type = SalesDocumentType::tryFrom((string) $record->type);
        $customer = $record->customer ?? $record->cashCustomer;
        $isPos = (bool) $type?->isPos();

        $columns = [
            'no' => ['label' => __('No.'), 'align' => 'start'],
            'code' => ['label' => __('Item Code'), 'align' => 'start'],
            'name' => ['label' => __('Item Name'), 'align' => 'start'],
            'unit' => ['label' => __('Unit'), 'align' => 'start'],
            'batch' => ['label' => __('Batch No. / Expiry Date'), 'align' => 'start'],
            'quantity' => ['label' => __('Quantity'), 'align' => 'end'],
            'price' => ['label' => __('Unit Price'), 'align' => 'end'],
            'gross' => ['label' => __('Gross Price'), 'align' => 'end'],
            'discount' => ['label' => __('Discount'), 'align' => 'end'],
            'tax_percent' => ['label' => __('Tax %'), 'align' => 'end'],
            'tax' => ['label' => __('Tax'), 'align' => 'end'],
            'net' => ['label' => __('Item Net'), 'align' => 'end'],
        ];

        $rows = $record->lines->values()->map(fn (SalesDocumentLine $line, int $index): array => [
            'no' => $index + 1,
            'code' => $line->item?->code,
            'name' => $this->itemName($line->item),
            'unit' => $line->unit?->name,
            'batch' => $line->itemBatch ? trim($line->itemBatch->batch_no.' / '.static::date($line->itemBatch->expiry_date), ' /') : '',
            'quantity' => static::quantity($line->quantity),
            'price' => static::money($line->unit_price),
            'gross' => static::money($line->gross),
            'discount' => static::money($line->discount),
            'tax_percent' => static::percent($line->tax_percent),
            'tax' => static::money($line->tax),
            'net' => static::money($line->net),
        ])->all();

        $totals = [
            [__('Gross Price'), static::money($record->gross_total), false],
            [__('Item Discounts'), static::money($record->items_discount), false],
            [__('Additional Discount'), static::money($record->additional_discount), false],
            [__('Total Excl. VAT'), static::money($record->net_total), false],
            [__('Total VAT'), static::money($record->tax_total), false],
            [__('Total Incl. VAT'), static::money($record->grand_total), true],
        ];

        if ($isPos || (float) $record->amount_received > 0) {
            $totals[] = [__('Cash'), static::money($record->cash_amount), false];
            $totals[] = [__('Visa / Mada'), static::money($record->card_amount), false];
            $totals[] = [__('Amount Received'), static::money($record->amount_received), false];
            $totals[] = [__('Change Due'), static::money($record->change_due), false];
        }

        return [
            'title' => static::title($record),
            'subtitle' => $type?->label(),
            'status' => $type?->isPostable() ? $this->statusLabel($record) : null,
            'number' => $record->number,
            'fields' => $this->fields([
                ['Invoice No.', $record->number],
                ['Invoice Date', static::date($record->date).($record->time ? ' '.substr((string) $record->time, 0, 5) : '')],
                ['Due Date', static::date($record->due_date)],
                ['Branch', $record->branch?->name],
                ['Warehouse', $record->warehouse?->name],
                ['Payment Method', filled($record->payment_method) ? __(ucfirst((string) $record->payment_method)) : null],
                ['Salesman', $record->salesman?->name],
                ['Doctor', $record->doctor?->name],
                ['Manual No.', $record->manual_no],
                ['Original Invoice No.', $record->originalDocument?->number],
                ['Invoice Counter (ICV)', $record->zatca_icv],
            ]),
            'partyTitle' => __('Buyer'),
            'partyFields' => $this->fields([
                ['Customer', $this->partyName($customer) ?? $record->pointsCustomer?->name],
                ['VAT No.', $customer?->vat_no],
                ['Commercial Register', $customer?->commercial_register ?? null],
                ['Address', $customer?->address ?: trim(($customer?->building_no ?? '').' '.($customer?->street_name ?? ''))],
                ['Phone', $customer?->phone ?? $customer?->mobile ?? $record->pointsCustomer?->mobile],
            ]),
            'columns' => $columns,
            'rows' => $rows,
            'footerRow' => null,
            'totals' => $totals,
            'grandTotal' => static::money($record->grand_total),
            'amountInWords' => AmountInWords::format((float) $record->grand_total),
            'notes' => $record->notes,
            'qr' => static::qr($record),
            'uuid' => $record->uuid,
            'signatures' => $isPos ? [] : [__('Prepared By'), __('Receiver'), __('Approved By')],
            'record' => $record,
        ];
    }
}
