<?php

namespace App\Services\Zatca;

use App\Models\Company;
use App\Models\EgsUnit;
use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * ZATCA (Fatoora) e-invoicing.
 *
 * Implemented:
 *  - invoice type selection (standard B2B when the buyer has a VAT number, simplified B2C otherwise)
 *  - invoice counter (ICV) and previous-invoice-hash (PIH) chain per EGS unit
 *  - UBL 2.1 XML generation and SHA-256 invoice hash
 *  - QR code TLV (tags 1-6) in Base64
 *  - submission to the reporting / clearance API when the EGS unit has a CSID
 *
 * Not implemented here (needs the taxpayer's keys and ZATCA onboarding):
 *  - CSR generation / compliance & production CSID onboarding
 *  - XAdES cryptographic stamp (tags 7-9 of the QR). Plug a signer in `sign()`.
 */
class ZatcaService
{
    public const INITIAL_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    public const ENDPOINTS = [
        'sandbox' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
        'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
        'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
    ];

    /** Assign ICV/PIH, build XML, hash and QR. Status becomes "pending". */
    public function prepare(SalesDocument $doc): SalesDocument
    {
        return DB::transaction(function () use ($doc) {
            $doc->loadMissing('lines.item', 'customer', 'cashCustomer', 'branch.company', 'originalDocument');
            $egs = $this->egsFor($doc);

            $doc->zatca_invoice_type = filled($doc->customer?->vat_no ?? $doc->cashCustomer?->vat_no) ? 'standard' : 'simplified';

            if (! $doc->zatca_icv) {
                if ($egs) {
                    $egs = EgsUnit::query()->lockForUpdate()->find($egs->id);
                    $doc->zatca_icv = $egs->last_icv + 1;
                    $doc->zatca_previous_hash = $egs->last_invoice_hash ?: self::INITIAL_PIH;
                } else {
                    $doc->zatca_icv = (int) SalesDocument::query()->max('zatca_icv') + 1;
                    $doc->zatca_previous_hash = SalesDocument::query()->whereNotNull('zatca_hash')->latest('zatca_icv')->value('zatca_hash') ?: self::INITIAL_PIH;
                }
            }

            $xml = (new UblInvoiceBuilder)->build($doc);
            $doc->zatca_hash = base64_encode(hash('sha256', $xml, true));
            $doc->zatca_xml = $this->sign($xml, $egs);
            $doc->zatca_qr = $this->qr($doc);
            $doc->zatca_status ??= 'pending';
            $doc->saveQuietly();

            $egs?->forceFill(['last_icv' => $doc->zatca_icv, 'last_invoice_hash' => $doc->zatca_hash])->saveQuietly();

            return $doc;
        });
    }

    /** Base64 TLV QR payload. */
    public function qr(SalesDocument $doc): string
    {
        $company = $doc->branch?->company ?? Company::query()->first();
        $timestamp = $doc->date->format('Y-m-d').'T'.($doc->time ?: $doc->created_at?->format('H:i:s') ?: '00:00:00');

        $tags = [
            1 => (string) ($company?->name_ar ?? config('app.name')),
            2 => (string) ($company?->vat_no ?? ''),
            3 => $timestamp,
            4 => number_format((float) $doc->grand_total, 2, '.', ''),
            5 => number_format((float) $doc->tax_total, 2, '.', ''),
        ];

        if ($doc->zatca_hash) {
            $tags[6] = $doc->zatca_hash;
        }

        return base64_encode(implode('', array_map(fn ($tag, $value) => chr($tag).chr(strlen($value)).$value, array_keys($tags), $tags)));
    }

    /** Decode a TLV QR back to [tag => value] (for display / tests). */
    public static function decodeQr(string $base64): array
    {
        $bin = base64_decode($base64);
        $out = [];
        for ($i = 0; $i < strlen($bin);) {
            $tag = ord($bin[$i]);
            $len = ord($bin[$i + 1]);
            $out[$tag] = substr($bin, $i + 2, $len);
            $i += 2 + $len;
        }

        return $out;
    }

    /** Hook for the XAdES signer. Returns the XML unchanged until keys are configured. */
    protected function sign(string $xml, ?EgsUnit $egs): string
    {
        return $xml;
    }

    public function egsFor(SalesDocument $doc): ?EgsUnit
    {
        return EgsUnit::query()->where('branch_id', $doc->branch_id)->where('is_active', true)->first();
    }

    /** Send to ZATCA (reporting for simplified, clearance for standard). */
    public function submit(SalesDocument $doc): SalesDocument
    {
        if (! $doc->zatca_xml) {
            $this->prepare($doc);
        }

        $egs = $this->egsFor($doc);
        $csid = $egs?->production_csid ?: $egs?->compliance_csid;
        $secret = $egs?->production_secret ?: $egs?->compliance_secret;

        if (! $egs || ! $csid) {
            $doc->forceFill([
                'zatca_status' => 'error',
                'zatca_error' => __('The branch has no onboarded EGS unit (CSID). Complete ZATCA onboarding in General > EGS Units.'),
            ])->saveQuietly();

            return $doc;
        }

        $path = $doc->zatca_invoice_type === 'standard' ? '/invoices/clearance/single' : '/invoices/reporting/single';
        $url = (self::ENDPOINTS[$egs->environment] ?? self::ENDPOINTS['sandbox']).$path;

        try {
            $response = Http::withBasicAuth($csid, (string) $secret)
                ->withHeaders(['Accept-Version' => 'V2', 'Accept-Language' => 'en', 'Clearance-Status' => $doc->zatca_invoice_type === 'standard' ? '1' : '0'])
                ->timeout(30)
                ->post($url, [
                    'invoiceHash' => $doc->zatca_hash,
                    'uuid' => $doc->uuid,
                    'invoice' => base64_encode($doc->zatca_xml),
                ]);

            $body = $response->json() ?? [];
            $status = $body['reportingStatus'] ?? $body['clearanceStatus'] ?? null;
            $errors = collect($body['validationResults']['errorMessages'] ?? [])->pluck('message')->implode(' | ');
            $warnings = collect($body['validationResults']['warningMessages'] ?? [])->pluck('message')->implode(' | ');

            $doc->forceFill([
                'zatca_status' => match (true) {
                    $status === 'REPORTED' => $warnings ? 'warning' : 'reported',
                    $status === 'CLEARED' => $warnings ? 'warning' : 'cleared',
                    $response->successful() => 'reported',
                    default => 'rejected',
                },
                'zatca_error' => $errors ?: ($warnings ?: ($response->successful() ? null : $response->body())),
                'zatca_submitted_at' => now(),
            ])->saveQuietly();
        } catch (\Throwable $e) {
            $doc->forceFill(['zatca_status' => 'error', 'zatca_error' => $e->getMessage()])->saveQuietly();
        }

        return $doc;
    }
}
