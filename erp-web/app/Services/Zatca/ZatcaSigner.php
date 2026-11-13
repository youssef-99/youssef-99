<?php

namespace App\Services\Zatca;

use App\Models\EgsUnit;

/**
 * ECDSA (SHA-256) signing with the EGS unit's private key, and the data the simplified-invoice
 * QR needs: tag 7 = signature, tag 8 = public key (DER), tag 9 = the CSID certificate's signature.
 *
 * Returns null when the EGS unit has no usable private key (before onboarding).
 * The full XAdES UBLExtensions block is not produced here.
 */
class ZatcaSigner
{
    /**
     * @return array{signature: string, public_key: string, certificate_signature: ?string}|null raw binary values
     */
    public function sign(string $canonicalXml, ?EgsUnit $egs): ?array
    {
        $key = $this->privateKey($egs?->private_key);
        if (! $key) {
            return null;
        }

        if (! openssl_sign($canonicalXml, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $details = openssl_pkey_get_details($key);
        $publicKey = $this->pemToDer((string) ($details['key'] ?? ''));

        return [
            'signature' => $signature,
            'public_key' => $publicKey,
            'certificate_signature' => $this->certificateSignature($egs?->production_csid ?: $egs?->compliance_csid),
        ];
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
