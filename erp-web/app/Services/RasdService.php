<?php

namespace App\Services;

use App\Exceptions\ErpException;
use App\Models\Branch;
use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentLine;
use DOMDocument;
use Illuminate\Support\Str;

/**
 * Rasd (رصد) — the SFDA drug track & trace system.
 *
 * prepare(): builds an RSD-style "supply / receive" XML notification for a purchase invoice, one
 *            <Product> per line with GTIN, batch number, expiry date, quantity (incl. bonus) and serials.
 * send():    there is no public Rasd API configured, so in sandbox mode (config erp.rasd.mode, default
 *            "sandbox") it stores a simulated acceptance response; any other mode fails with a message.
 *
 * Status column `rasd_status`: prepared | sent | failed.
 */
class RasdService
{
    public static function mode(): string
    {
        return (string) config('erp.rasd.mode', 'sandbox');
    }

    public static function fileName(PurchaseDocument $doc): string
    {
        return 'rasd-'.Str::slug($doc->type).'-'.$doc->number.'.xml';
    }

    public static function build(PurchaseDocument $doc): string
    {
        $doc->loadMissing('lines.item', 'lines.itemBatch', 'supplier', 'branch', 'cashSupplier');

        $lines = $doc->lines->filter(fn (PurchaseDocumentLine $l) => filled($l->item?->gtin) || filled($l->item?->barcode));
        if ($lines->isEmpty()) {
            throw new ErpException(__('No invoice items have a GTIN to report to Rasd.'));
        }

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->appendChild($xml->createElement('RasdNotification'));
        $root->setAttribute('type', $doc->typeEnum()->isReturn() ? 'RETURN' : 'ACCEPT');

        $header = $root->appendChild($xml->createElement('Header'));
        $supplier = $doc->supplier ?? $doc->cashSupplier;
        $branch = $doc->branch ?? Branch::query()->find($doc->branch_id);
        foreach ([
            'NotificationId' => (string) Str::uuid(),
            'DocumentType' => $doc->type,
            'DocumentNumber' => (string) $doc->number,
            'DocumentDate' => $doc->date?->toDateString(),
            'SupplierInvoiceNumber' => (string) $doc->supplier_invoice_no,
            'SupplierName' => (string) $supplier?->name,
            'SupplierVatNumber' => (string) $supplier?->vat_no,
            'SupplierGLN' => (string) ($doc->supplier?->gln ?? ''),
            'ReceiverBranch' => (string) $branch?->name,
        ] as $tag => $value) {
            $header->appendChild($xml->createElement($tag))->appendChild($xml->createTextNode((string) $value));
        }

        $products = $root->appendChild($xml->createElement('Products'));
        foreach ($lines as $line) {
            $product = $products->appendChild($xml->createElement('Product'));
            $gtin = (string) ($line->item->gtin ?: $line->item->barcode);
            $expiry = $line->expiry_date ?? $line->itemBatch?->expiry_date;
            foreach ([
                'GTIN' => str_pad($gtin, 14, '0', STR_PAD_LEFT),
                'ItemCode' => (string) $line->item->code,
                'ItemName' => (string) $line->item->name,
                'BatchNumber' => (string) ($line->batch_no ?? $line->itemBatch?->batch_no ?? ''),
                'ExpiryDate' => $expiry ? $expiry->toDateString() : '',
                'Quantity' => rtrim(rtrim(number_format((float) $line->quantity + (float) $line->bonus_qty, 3, '.', ''), '0'), '.'),
            ] as $tag => $value) {
                $product->appendChild($xml->createElement($tag))->appendChild($xml->createTextNode($value));
            }
            $serials = $product->appendChild($xml->createElement('SerialNumbers'));
            foreach ((array) ($line->serial_numbers ?? []) as $serial) {
                $serials->appendChild($xml->createElement('SerialNumber'))->appendChild($xml->createTextNode((string) $serial));
            }
        }

        return (string) $xml->saveXML();
    }

    /** Build and store the Rasd file. */
    public static function prepare(PurchaseDocument $doc): PurchaseDocument
    {
        if (! $doc->is_posted) {
            throw new ErpException(__('Post the invoice before sending it to Rasd.'));
        }

        $doc->forceFill([
            'rasd_file' => static::build($doc),
            'rasd_status' => $doc->rasd_status === 'sent' ? 'sent' : 'prepared',
        ])->saveQuietly();

        return $doc;
    }

    public static function send(PurchaseDocument $doc): PurchaseDocument
    {
        if (! $doc->rasd_file) {
            static::prepare($doc);
        }

        if (static::mode() !== 'sandbox') {
            $doc->forceFill([
                'rasd_status' => 'failed',
                'rasd_response' => __('Rasd integration is not configured. Upload the prepared file to the Rasd portal.'),
            ])->saveQuietly();

            throw new ErpException($doc->rasd_response);
        }

        $reference = 'RSD-SANDBOX-'.strtoupper(Str::random(10));
        $doc->forceFill([
            'rasd_status' => 'sent',
            'rasd_sent_at' => now(),
            'rasd_reference' => $reference,
            'rasd_response' => json_encode([
                'mode' => 'sandbox',
                'status' => 'ACCEPTED',
                'notificationReference' => $reference,
                'products' => substr_count((string) $doc->rasd_file, '<Product>'),
                'receivedAt' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE),
        ])->saveQuietly();

        return $doc;
    }
}
