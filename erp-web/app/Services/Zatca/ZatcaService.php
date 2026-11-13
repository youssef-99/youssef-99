<?php

namespace App\Services\Zatca;

use App\Enums\SalesDocumentType;
use App\Exceptions\ErpException;
use App\Models\Company;
use App\Models\EgsUnit;
use App\Models\SalesDocument;
use App\Models\Setting;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * ZATCA (Fatoora) e-invoicing.
 *
 *  - invoice type: standard (B2B) when the buyer has a VAT number, simplified (B2C) otherwise;
 *    credit / debit notes inherit the type of the invoice they reference
 *  - invoice counter (ICV) and previous-invoice-hash (PIH) chain per EGS unit (global chain when the branch has none);
 *    the counter is assigned once, so regenerating / resubmitting keeps the document's place in the chain
 *  - UBL 2.1 XML, canonicalised (C14N) SHA-256 invoice hash
 *  - ECDSA signature with the EGS private key when onboarded (ZatcaSigner)
 *  - QR TLV: tags 1-5 always, 6 hash, 7 signature, 8 public key, 9 certificate signature (simplified)
 *  - submission: reporting (simplified) or clearance (standard); `erp.zatca.offline` simulates acceptance
 *    without any network call; failed documents can be resubmitted
 */
class ZatcaService
{
    public const INITIAL_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    public const ENDPOINTS = [
        'sandbox' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
        'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
        'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
    ];

    /** Submission statuses (حالة الارسال). */
    public const STATUSES = [
        'pending' => 'Not Sent',
        'reported' => 'Reported',
        'cleared' => 'Cleared',
        'warning' => 'Accepted with Warnings',
        'rejected' => 'Rejected',
        'error' => 'Error',
    ];

    public const ACCEPTED = ['reported', 'cleared', 'warning'];

    public const FALLBACK_CHAIN_KEY = 'zatca.fallback_chain';

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'reported', 'cleared' => 'success',
            'warning' => 'warning',
            'rejected', 'error' => 'danger',
            default => 'gray',
        };
    }

    public function invoiceTypeFor(SalesDocument $doc): string
    {
        if ($doc->original_document_id && in_array($doc->type, [ZatcaNoteService::CREDIT_NOTE, ZatcaNoteService::DEBIT_NOTE], true)) {
            $original = $doc->originalDocument;
            if ($original?->zatca_invoice_type) {
                return $original->zatca_invoice_type;
            }
            $doc = $original ?? $doc;
        }

        return filled($doc->customer?->vat_no ?? $doc->cashCustomer?->vat_no) ? 'standard' : 'simplified';
    }

    /** Returns and notes must reference the invoice they correct (BR-KSA-56, billing reference). */
    public function assertReportable(SalesDocument $doc): void
    {
        $type = SalesDocumentType::tryFrom((string) $doc->type);
        $needsReference = $type?->isReturn() || in_array($doc->type, ZatcaNoteService::TYPES, true);

        if ($needsReference && ! $doc->original_document_id) {
            throw new ErpException(__('Select the original invoice: ZATCA credit and debit notes must reference the invoice they correct.'));
        }
    }

    /** Once a document has its place in the ZATCA chain (ICV) it can no longer be unposted or deleted. */
    public static function assertCanUnpost(SalesDocument $doc): void
    {
        if (in_array($doc->zatca_status, self::ACCEPTED, true)) {
            throw new ErpException(__('This invoice was reported to ZATCA. Issue a return or credit note instead.'));
        }

        if ($doc->zatca_icv) {
            throw new ErpException(__('This document has a ZATCA invoice counter (ICV). Issue a credit note instead.'));
        }
    }

    /**
     * Assign ICV/PIH (once), build the final XML, hash it, sign (XAdES) and add the QR. Status becomes "pending" when new.
     * Runs inside the posting transaction; the EGS row lock is released when that transaction commits
     * (no network call happens while it is held: submission is deferred until after commit).
     */
    public function prepare(SalesDocument $doc): SalesDocument
    {
        $this->assertReportable($doc);

        return DB::transaction(function () use ($doc) {
            $doc->loadMissing('lines.item', 'customer', 'cashCustomer', 'branch.company', 'originalDocument.customer', 'originalDocument.cashCustomer');
            $egs = $this->egsFor($doc);
            $isNew = ! $doc->zatca_icv;
            $fallback = null;

            $doc->zatca_invoice_type = $this->invoiceTypeFor($doc);

            if ($isNew) {
                if ($egs) {
                    $egs = EgsUnit::query()->lockForUpdate()->find($egs->id);
                    $doc->zatca_icv = $egs->last_icv + 1;
                    $doc->zatca_previous_hash = $egs->last_invoice_hash ?: self::INITIAL_PIH;
                } else {
                    $fallback = $this->lockFallbackChain();
                    $chain = json_decode((string) $fallback->value, true) ?: [];
                    $doc->zatca_icv = (int) ($chain['icv'] ?? 0) + 1;
                    $doc->zatca_previous_hash = ($chain['hash'] ?? null) ?: self::INITIAL_PIH;
                }
            }

            $dom = ZatcaXml::load((new UblInvoiceBuilder)->build($doc, withSignature: (bool) $egs?->private_key));
            $doc->zatca_hash = ZatcaXml::invoiceHash($dom);

            $signed = app(ZatcaSigner::class)->sign(base64_decode($doc->zatca_hash), $egs);
            if ($signed) {
                ZatcaXml::applySignature($dom, $doc->zatca_hash, $signed, now());
            }

            $doc->zatca_qr = $this->qr($doc, $signed);
            ZatcaXml::setQr($dom, $doc->zatca_qr);
            $doc->zatca_xml = (string) $dom->saveXML();
            $doc->zatca_status ??= 'pending';
            $doc->saveQuietly();

            if ($isNew && $egs) {
                $egs->forceFill(['last_icv' => $doc->zatca_icv, 'last_invoice_hash' => $doc->zatca_hash])->saveQuietly();
            }

            if ($fallback) {
                $fallback->forceFill(['value' => json_encode(['icv' => $doc->zatca_icv, 'hash' => $doc->zatca_hash])])->save();
            }

            return $doc;
        });
    }

    /**
     * Branches without an EGS unit share one chain kept in a locked settings row
     * (initialised from the highest ICV already issued without an EGS unit).
     */
    protected function lockFallbackChain(): Setting
    {
        $row = Setting::query()->where('key', self::FALLBACK_CHAIN_KEY)->lockForUpdate()->first();

        if (! $row) {
            $last = SalesDocument::query()->whereNotNull('zatca_icv')->orderByDesc('zatca_icv')->first(['zatca_icv', 'zatca_hash']);
            Setting::query()->insertOrIgnore([
                'key' => self::FALLBACK_CHAIN_KEY,
                'value' => json_encode(['icv' => (int) $last?->zatca_icv, 'hash' => $last?->zatca_hash]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = Setting::query()->where('key', self::FALLBACK_CHAIN_KEY)->lockForUpdate()->firstOrFail();
        }

        return $row;
    }

    /**
     * Base64 TLV QR payload.
     *
     * @param  array{signature: string, public_key: string, certificate_signature: ?string}|null  $signed
     */
    public function qr(SalesDocument $doc, ?array $signed = null): string
    {
        $company = $doc->branch?->company ?? Company::query()->first();
        $doc->loadMissing('lines.item');
        $amounts = UblInvoiceBuilder::breakdown($doc);

        $tags = [
            1 => (string) ($company?->name_ar ?? config('app.name')),
            2 => (string) ($company?->vat_no ?? ''),
            3 => static::timestamp($doc),
            4 => number_format($amounts['tax_inclusive'], 2, '.', ''),
            5 => number_format($amounts['tax_total'], 2, '.', ''),
        ];

        if ($doc->zatca_hash) {
            $tags[6] = $doc->zatca_hash;
        }

        if ($signed) {
            $tags[7] = base64_encode($signed['signature']);
            $tags[8] = $signed['public_key'];
            if ($doc->zatca_invoice_type === 'simplified' && $signed['certificate_signature']) {
                $tags[9] = $signed['certificate_signature'];
            }
        }

        $tlv = '';
        foreach ($tags as $tag => $value) {
            $value = strlen($value) > 255 ? mb_strcut($value, 0, 255, 'UTF-8') : $value;
            $tlv .= chr($tag).chr(strlen($value)).$value;
        }

        return base64_encode($tlv);
    }

    public static function timestamp(SalesDocument $doc): string
    {
        return $doc->date->format('Y-m-d').'T'.static::time($doc);
    }

    public static function time(SalesDocument $doc): string
    {
        return substr((string) ($doc->time ?: $doc->created_at?->format('H:i:s') ?: '00:00:00'), 0, 8);
    }

    /** Decode a TLV QR back to [tag => value] (for display / tests). */
    public static function decodeQr(string $base64): array
    {
        $bin = (string) base64_decode($base64);
        $out = [];
        for ($i = 0; $i + 1 < strlen($bin);) {
            $tag = ord($bin[$i]);
            $len = ord($bin[$i + 1]);
            $out[$tag] = substr($bin, $i + 2, $len);
            $i += 2 + $len;
        }

        return $out;
    }

    /** The branch's active EGS unit, preferring an onboarded one (production, then compliance CSID). */
    public function egsFor(SalesDocument $doc): ?EgsUnit
    {
        return EgsUnit::query()
            ->where('branch_id', $doc->branch_id)
            ->where('is_active', true)
            ->orderByRaw('case when production_csid is not null then 0 when compliance_csid is not null then 1 else 2 end')
            ->orderBy('id')
            ->first();
    }

    public static function isOffline(): bool
    {
        return (bool) config('erp.zatca.offline', false);
    }

    /** System Settings > ZATCA E-Invoicing (falls back to config/erp.php). */
    public static function enabled(): bool
    {
        return (bool) Settings::get('zatca.enabled', config('erp.zatca.enabled', true));
    }

    /** on_post | manual */
    public static function submissionMode(): string
    {
        return (string) Settings::get('zatca.submission_mode', config('erp.zatca.submission_mode', 'on_post'));
    }

    public static function autoResubmit(): bool
    {
        return (bool) Settings::get('zatca.auto_resubmit', true);
    }

    public static function environmentFor(?EgsUnit $egs): string
    {
        return (string) ($egs?->environment ?: Settings::get('zatca.environment', config('erp.zatca.environment', 'sandbox')));
    }

    /**
     * After posting: in "on_post" mode, submit once the posting transaction has committed (never while the
     * EGS row is locked, never for a posting that rolls back). Only the current document is sent inline;
     * the backlog (pending / failed documents) is retried by the scheduled `zatca:submit-pending` command.
     */
    public function afterPost(SalesDocument $doc): SalesDocument
    {
        if (! static::enabled() || static::submissionMode() !== 'on_post' || ! $this->canSendNow($doc)) {
            return $doc;
        }

        DB::afterCommit(function () use ($doc) {
            try {
                $this->submit($doc->refresh());
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $doc;
    }

    /** Onboarded EGS unit (or offline mode) available for the document's branch. */
    public function canSendNow(SalesDocument $doc): bool
    {
        $egs = $this->egsFor($doc);

        return static::isOffline() || filled($egs?->production_csid ?: $egs?->compliance_csid);
    }

    /**
     * Submit posted documents still pending or in error (oldest ICV first), used by `zatca:submit-pending`.
     *
     * @return array{sent: int, accepted: int, failed: int}
     */
    public function submitPending(int $olderThanMinutes = 5, int $limit = 100, bool $includeErrors = true): array
    {
        $result = ['sent' => 0, 'accepted' => 0, 'failed' => 0];
        if (! static::enabled()) {
            return $result;
        }

        $statuses = $includeErrors && static::autoResubmit() ? ['pending', 'error'] : ['pending'];

        SalesDocument::query()
            ->where('is_posted', true)
            ->whereNotNull('zatca_icv')
            ->whereIn('zatca_status', $statuses)
            ->where('posted_at', '<=', now()->subMinutes($olderThanMinutes))
            ->orderBy('zatca_icv')
            ->limit($limit)
            ->get()
            ->each(function (SalesDocument $doc) use (&$result) {
                if (! $this->canSendNow($doc)) {
                    return;
                }
                $doc = $doc->zatca_status === 'error' ? $this->resubmit($doc) : $this->submit($doc);
                $result['sent']++;
                $result[in_array($doc->zatca_status, self::ACCEPTED, true) ? 'accepted' : 'failed']++;
            });

        return $result;
    }

    public function canSubmit(SalesDocument $doc): bool
    {
        return $doc->is_posted && ! in_array($doc->zatca_status, self::ACCEPTED, true);
    }

    /** Regenerate the XML (same ICV / PIH) and send again. */
    public function resubmit(SalesDocument $doc): SalesDocument
    {
        if (! $this->canSubmit($doc)) {
            throw new ErpException(__('This document was already accepted by ZATCA.'));
        }

        $this->prepare($doc);

        return $this->submit($doc);
    }

    /** Send to ZATCA (reporting for simplified, clearance for standard). */
    public function submit(SalesDocument $doc): SalesDocument
    {
        if (! $doc->is_posted) {
            throw new ErpException(__('Post the document before sending it to ZATCA.'));
        }

        if (in_array($doc->zatca_status, self::ACCEPTED, true)) {
            return $doc;
        }

        if (! $doc->zatca_xml) {
            $this->prepare($doc);
        }

        $standard = $doc->zatca_invoice_type === 'standard';
        $doc->zatca_attempts = (int) $doc->zatca_attempts + 1;

        if (static::isOffline()) {
            $doc->forceFill([
                'zatca_status' => $standard ? 'cleared' : 'reported',
                'zatca_error' => null,
                'zatca_submitted_at' => now(),
            ])->saveQuietly();

            return $doc;
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

        $path = $standard ? '/invoices/clearance/single' : '/invoices/reporting/single';
        $url = (self::ENDPOINTS[static::environmentFor($egs)] ?? self::ENDPOINTS['sandbox']).$path;

        try {
            $response = Http::withBasicAuth($csid, (string) $secret)
                ->acceptJson()
                ->withHeaders(['Accept-Version' => 'V2', 'Accept-Language' => 'en', 'Clearance-Status' => $standard ? '1' : '0'])
                ->timeout(30)
                ->post($url, [
                    'invoiceHash' => $doc->zatca_hash,
                    'uuid' => $doc->uuid,
                    'invoice' => base64_encode((string) $doc->zatca_xml),
                ]);

            $body = $response->json() ?? [];
            $status = $body['reportingStatus'] ?? $body['clearanceStatus'] ?? null;
            $errors = collect($body['validationResults']['errorMessages'] ?? [])->map(fn ($m) => trim(($m['code'] ?? '').' '.($m['message'] ?? '')))->implode(' | ');
            $warnings = collect($body['validationResults']['warningMessages'] ?? [])->map(fn ($m) => trim(($m['code'] ?? '').' '.($m['message'] ?? '')))->implode(' | ');

            $newStatus = match (true) {
                in_array($status, ['REPORTED', 'CLEARED'], true) && $warnings !== '' => 'warning',
                $status === 'REPORTED' => 'reported',
                $status === 'CLEARED' => 'cleared',
                in_array($status, ['NOT_REPORTED', 'NOT_CLEARED'], true) => 'rejected',
                $response->status() === 409 => $standard ? 'cleared' : 'reported',
                $response->successful() => $standard ? 'cleared' : 'reported',
                $response->clientError() => 'rejected',
                default => 'error',
            };

            $updates = [
                'zatca_status' => $newStatus,
                'zatca_error' => $errors ?: ($warnings ?: (in_array($newStatus, ['rejected', 'error'], true) ? mb_substr($response->status().' '.$response->body(), 0, 2000) : null)),
                'zatca_submitted_at' => now(),
            ];

            if ($standard && filled($body['clearedInvoice'] ?? null)) {
                $updates['zatca_xml'] = base64_decode($body['clearedInvoice']);
            }

            $doc->forceFill($updates)->saveQuietly();
        } catch (\Throwable $e) {
            $doc->forceFill(['zatca_status' => 'error', 'zatca_error' => $e->getMessage()])->saveQuietly();
        }

        return $doc;
    }
}
