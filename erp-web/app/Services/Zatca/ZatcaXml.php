<?php

namespace App\Services\Zatca;

use Carbon\CarbonInterface;

/**
 * DOM operations on the final invoice XML: ZATCA invoice hash, XAdES signature values and the QR.
 *
 * Invoice hash: from the same document that is stored / submitted, remove ext:UBLExtensions, the invoice's
 * cac:Signature and the QR AdditionalDocumentReference, canonicalise, SHA-256, base64.
 * PHP's DOM offers Canonical XML 1.0 (inclusive); for these documents (no xml:id / xml:base, no
 * relative namespaces) it produces the same octets as C14N 1.1, which the signature declares.
 */
class ZatcaXml
{
    public static function load(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);

        return $dom;
    }

    public static function xpath(\DOMDocument $dom): \DOMXPath
    {
        $xpath = new \DOMXPath($dom);
        foreach (UblInvoiceBuilder::NS as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }

    /** Canonical form the invoice hash is computed over. */
    public static function hashInput(\DOMDocument $dom): string
    {
        $copy = static::load((string) $dom->saveXML());
        $xpath = static::xpath($copy);

        foreach (['/inv:Invoice/ext:UBLExtensions', '/inv:Invoice/cac:Signature', "/inv:Invoice/cac:AdditionalDocumentReference[cbc:ID='QR']"] as $query) {
            foreach (iterator_to_array($xpath->query($query)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        return (string) $copy->C14N(false, false);
    }

    /** Base64 SHA-256 invoice hash. */
    public static function invoiceHash(\DOMDocument|string $xml): string
    {
        $dom = is_string($xml) ? static::load($xml) : $xml;

        return base64_encode(hash('sha256', static::hashInput($dom), true));
    }

    /**
     * Fill the XAdES skeleton.
     *
     * @param  array{signature: string, public_key: string, certificate: ?string, certificate_signature: ?string, issuer: ?string, serial: ?string}  $signed
     */
    public static function applySignature(\DOMDocument $dom, string $invoiceHash, array $signed, CarbonInterface $signingTime): void
    {
        $xpath = static::xpath($dom);
        $root = '/inv:Invoice/ext:UBLExtensions//ds:Signature';

        static::set($xpath, $root.'/ds:Object//xades:SigningTime', $signingTime->format('Y-m-d\TH:i:s'));
        static::set($xpath, $root.'/ds:Object//xades:CertDigest/ds:DigestValue', base64_encode(hash('sha256', (string) $signed['certificate'])));
        static::set($xpath, $root.'/ds:Object//ds:X509IssuerName', (string) $signed['issuer']);
        static::set($xpath, $root.'/ds:Object//ds:X509SerialNumber', (string) $signed['serial']);

        $properties = $xpath->query($root.'/ds:Object//xades:SignedProperties')->item(0);
        $propertiesDigest = $properties ? base64_encode(hash('sha256', (string) $properties->C14N(false, false))) : '';

        static::set($xpath, $root."/ds:SignedInfo/ds:Reference[@Id='invoiceSignedData']/ds:DigestValue", $invoiceHash);
        static::set($xpath, $root."/ds:SignedInfo/ds:Reference[@URI='#xadesSignedProperties']/ds:DigestValue", $propertiesDigest);
        static::set($xpath, $root.'/ds:SignatureValue', base64_encode($signed['signature']));
        static::set($xpath, $root.'/ds:KeyInfo/ds:X509Data/ds:X509Certificate', (string) $signed['certificate']);
    }

    public static function setQr(\DOMDocument $dom, string $qr): void
    {
        static::set(static::xpath($dom), "/inv:Invoice/cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject", $qr);
    }

    protected static function set(\DOMXPath $xpath, string $query, string $value): void
    {
        $node = $xpath->query($query)->item(0);
        if ($node) {
            $node->nodeValue = '';
            $node->appendChild($node->ownerDocument->createTextNode($value));
        }
    }
}
