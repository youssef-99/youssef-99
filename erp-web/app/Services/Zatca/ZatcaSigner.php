<?php

namespace App\Services\Zatca;

use App\Models\EgsUnit;

/**
 * ECDSA (secp256k1 / P-256, SHA-256) signing with the EGS unit's private key.
 *
 * Per the ZATCA security features specification the SignatureValue is the ECDSA signature of the
 * invoice hash (not of ds:SignedInfo), and the same value is QR tag 7; tag 8 is the public key (DER),
 * tag 9 the CSID certificate's own signature (simplified invoices).
 *
 * Returns null when the EGS unit has no usable private key (before onboarding).
 */
class ZatcaSigner
{
    /**
     * @param  string  $invoiceHash  raw 32-byte SHA-256 digest
     * @return array{signature: string, public_key: string, certificate: ?string, certificate_signature: ?string, issuer: ?string, serial: ?string}|null
     */
    public function sign(string $invoiceHash, ?EgsUnit $egs): ?array
    {
        $key = $this->privateKey($egs?->private_key);
        if (! $key) {
            return null;
        }

        if (! openssl_sign($invoiceHash, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $details = openssl_pkey_get_details($key);
        $csid = $egs?->production_csid ?: $egs?->compliance_csid;
        $der = $csid ? $this->certificateDer(trim($csid)) : null;
        $parsed = $der ? openssl_x509_parse($this->derToPem($der)) : false;

        return [
            'signature' => $signature,
            'public_key' => $this->pemToDer((string) ($details['key'] ?? '')),
            'certificate' => $der ? base64_encode($der) : null,
            'certificate_signature' => $der ? $this->certificateSignature($csid) : null,
            'issuer' => $parsed ? $this->issuerName($parsed['issuer'] ?? []) : null,
            'serial' => $parsed ? (string) ($parsed['serialNumber'] ?? '') : null,
        ];
    }

    /** @param  array<string, string|array<int, string>>  $issuer */
    protected function issuerName(array $issuer): string
    {
        $parts = [];
        foreach (array_reverse($issuer, true) as $name => $value) {
            foreach ((array) $value as $v) {
                $parts[] = $name.'='.$v;
            }
        }

        return implode(', ', $parts);
    }

    protected function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }

    protected function privateKey(?string $value): \OpenSSLAsymmetricKey|false
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        if (! str_contains($value, '-----BEGIN')) {
            $value = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split($value, 64, "\n")."-----END EC PRIVATE KEY-----\n";
        }

        return @openssl_pkey_get_private($value);
    }

    public function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----[^-]+-----|\s+/', '', $pem);

        return (string) base64_decode((string) $body, true);
    }

    /**
     * The signature value of the X.509 certificate (CSID binarySecurityToken, i.e. base64 of the base64 PEM body).
     */
    public function certificateSignature(?string $csid): ?string
    {
        $csid = trim((string) $csid);
        if ($csid === '') {
            return null;
        }

        $der = $this->certificateDer($csid);
        if ($der === null) {
            return null;
        }

        // Certificate ::= SEQUENCE { tbsCertificate, signatureAlgorithm, signatureValue BIT STRING }
        [$tag, $length, $offset] = $this->readHeader($der, 0);
        if ($tag !== 0x30) {
            return null;
        }

        for ($i = 0; $i < 2; $i++) {
            [, $len, $headerEnd] = $this->readHeader($der, $offset);
            $offset = $headerEnd + $len;
        }

        [$tag, $len, $headerEnd] = $this->readHeader($der, $offset);
        if ($tag !== 0x03 || $len < 2) {
            return null;
        }

        return substr($der, $headerEnd + 1, $len - 1);
    }

    protected function certificateDer(string $csid): ?string
    {
        if (str_contains($csid, '-----BEGIN')) {
            return $this->pemToDer($csid);
        }

        $candidate = base64_decode($csid, true);
        if ($candidate === false) {
            return null;
        }

        if (isset($candidate[0]) && ord($candidate[0]) === 0x30) {
            return $candidate;
        }

        $inner = base64_decode(preg_replace('/\s+/', '', $candidate) ?? '', true);

        return $inner !== false && isset($inner[0]) && ord($inner[0]) === 0x30 ? $inner : null;
    }

    /** @return array{0: int, 1: int, 2: int} tag, content length, offset of the content */
    protected function readHeader(string $der, int $offset): array
    {
        $tag = ord($der[$offset] ?? "\0");
        $length = ord($der[$offset + 1] ?? "\0");
        $pos = $offset + 2;

        if ($length & 0x80) {
            $bytes = $length & 0x7F;
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $length = ($length << 8) | ord($der[$pos + $i] ?? "\0");
            }
            $pos += $bytes;
        }

        return [$tag, $length, $pos];
    }
}
