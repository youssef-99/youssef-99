<?php

namespace App\Services\Zatca;

use App\Enums\SalesDocumentType;
use App\Models\Company;
use App\Models\SalesDocument;

/**
 * Builds the ZATCA UBL 2.1 invoice (388), credit note (381) or debit note (383).
 *
 * The output always contains the QR AdditionalDocumentReference and, when $withSignature, the
 * ext:UBLExtensions XAdES skeleton and cac:Signature. Their values are filled by ZatcaXml after hashing,
 * because ZATCA's invoice hash excludes exactly these three parts.
 *
 * Amounts come from breakdown(): one rounding path for lines, VAT category subtotals and header
 * (BR-CO-14, BR-KSA-EN16931-11); document allowances are split per VAT category.
 */
class UblInvoiceBuilder
{
    public const NS = [
        'inv' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
        'sig' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2',
        'sac' => 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2',
        'sbc' => 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2',
        'ds' => 'http://www.w3.org/2000/09/xmldsig#',
        'xades' => 'http://uri.etsi.org/01903/v1.3.2#',
    ];

    /**
     * Line, category and header amounts, all derived from the same rounded figures.
     *
     * @return array{
     *     lines: array<int, array{net: float, tax: float, price: float, net_price: float, unit_allowance: float, category: array}>,
     *     groups: array<string, array{category: array, line_total: float, allowance: float, taxable: float, tax: float}>,
     *     line_total: float, allowance_total: float, tax_exclusive: float, tax_total: float, tax_inclusive: float
     * }
     */
    public static function breakdown(SalesDocument $doc): array
    {
        $lines = [];
        $groups = [];

        foreach ($doc->lines->values() as $i => $line) {
            $quantity = (float) $line->quantity;
            $price = round((float) $line->unit_price, 6);
            $net = round(round($quantity * $price, 2) - (float) $line->discount, 2);
            $category = ZatcaTaxCategory::resolve((float) $line->tax_percent, $line->item?->tax_exemption_reason);
            $netPrice = ($quantity > 0 && (float) $line->discount > 0) ? round($net / $quantity, 6) : $price;

            $lines[$i] = [
                'net' => $net,
                'tax' => round($net * $category['rate'] / 100, 2),
                'price' => $price,
                'net_price' => $netPrice,
                'unit_allowance' => round($price - $netPrice, 6),
                'category' => $category,
            ];

            $key = ZatcaTaxCategory::key($category);
            $groups[$key] ??= ['category' => $category, 'line_total' => 0.0, 'allowance' => 0.0, 'taxable' => 0.0, 'tax' => 0.0];
            $groups[$key]['line_total'] = round($groups[$key]['line_total'] + $net, 2);
        }

        $lineTotal = round(array_sum(array_column($lines, 'net')), 2);
        $allowanceTotal = round(min((float) $doc->additional_discount, $lineTotal), 2);

        // Split the document allowance over the categories in proportion to their line totals.
        $remaining = $allowanceTotal;
        $keys = array_keys($groups);
        foreach ($keys as $n => $key) {
            $share = $n === count($keys) - 1
                ? $remaining
                : round($lineTotal > 0 ? $allowanceTotal * $groups[$key]['line_total'] / $lineTotal : 0, 2);
            $remaining = round($remaining - $share, 2);
            $groups[$key]['allowance'] = $share;
            $groups[$key]['taxable'] = round($groups[$key]['line_total'] - $share, 2);
            $groups[$key]['tax'] = round($groups[$key]['taxable'] * $groups[$key]['category']['rate'] / 100, 2);
        }

        $taxExclusive = round($lineTotal - $allowanceTotal, 2);
        $taxTotal = round(array_sum(array_column($groups, 'tax')), 2);

        return [
            'lines' => $lines,
            'groups' => $groups,
            'line_total' => $lineTotal,
            'allowance_total' => $allowanceTotal,
            'tax_exclusive' => $taxExclusive,
            'tax_total' => $taxTotal,
            'tax_inclusive' => round($taxExclusive + $taxTotal, 2),
        ];
    }

    public function build(SalesDocument $doc, bool $withSignature = false): string
    {
        $type = SalesDocumentType::tryFrom((string) $doc->type);
        $company = $doc->branch?->company ?? Company::query()->first();
        $isDebit = $doc->type === ZatcaNoteService::DEBIT_NOTE;
        $isReturn = ! $isDebit && ($doc->type === ZatcaNoteService::CREDIT_NOTE || (bool) $type?->isReturn());
        $standard = $doc->zatca_invoice_type === 'standard';
        $typeCode = match (true) {
            $isDebit => '383',
            $isReturn => '381',
            default => '388',
        };
        // KSA-2: 0100000 = standard tax invoice, 0200000 = simplified
        $subtype = $standard ? '0100000' : '0200000';
        $buyer = $doc->customer ?? $doc->cashCustomer ?? $doc->originalDocument?->customer ?? $doc->originalDocument?->cashCustomer;
        $reason = $doc->zatca_note_reason ?: ($doc->notes ?: 'Return of goods');
        $b = static::breakdown($doc);

        $x = new \XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->setIndentString('    ');
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('Invoice');
        $x->writeAttribute('xmlns', self::NS['inv']);
        $x->writeAttribute('xmlns:cac', self::NS['cac']);
        $x->writeAttribute('xmlns:cbc', self::NS['cbc']);
        $x->writeAttribute('xmlns:ext', self::NS['ext']);

        if ($withSignature) {
            $this->signatureExtension($x);
        }

        $x->writeElement('cbc:ProfileID', 'reporting:1.0');
        $x->writeElement('cbc:ID', (string) $doc->number);
        $x->writeElement('cbc:UUID', (string) $doc->uuid);
        $x->writeElement('cbc:IssueDate', $doc->date->format('Y-m-d'));
        $x->writeElement('cbc:IssueTime', ZatcaService::time($doc));
        $x->startElement('cbc:InvoiceTypeCode');
        $x->writeAttribute('name', $subtype);
        $x->text($typeCode);
        $x->endElement();
        $x->writeElement('cbc:DocumentCurrencyCode', 'SAR');
        $x->writeElement('cbc:TaxCurrencyCode', 'SAR');

        if (($isReturn || $isDebit) && $doc->originalDocument) {
            $x->startElement('cac:BillingReference');
            $x->startElement('cac:InvoiceDocumentReference');
            $x->writeElement('cbc:ID', (string) $doc->originalDocument->number);
            $x->endElement();
            $x->endElement();
        }

        $this->additionalRef($x, 'ICV', null, (string) $doc->zatca_icv);
        $this->additionalRef($x, 'PIH', (string) $doc->zatca_previous_hash, null);
        $this->additionalRef($x, 'QR', '', null);

        if ($withSignature) {
            $x->startElement('cac:Signature');
            $x->writeElement('cbc:ID', 'urn:oasis:names:specification:ubl:signature:Invoice');
            $x->writeElement('cbc:SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
            $x->endElement();
        }

        // Seller
        $x->startElement('cac:AccountingSupplierParty');
        $x->startElement('cac:Party');
        $x->startElement('cac:PartyIdentification');
        $x->startElement('cbc:ID');
        $x->writeAttribute('schemeID', 'CRN');
        $x->text((string) $company?->commercial_register);
        $x->endElement();
        $x->endElement();
        $this->address($x, $company?->street_name, $company?->building_no, $company?->additional_no, $company?->city_name, $company?->postal_code, $company?->district_name);
        $this->taxScheme($x, (string) $company?->vat_no);
        $x->startElement('cac:PartyLegalEntity');
        $x->writeElement('cbc:RegistrationName', (string) ($company?->name_ar ?? config('app.name')));
        $x->endElement();
        $x->endElement();
        $x->endElement();

        // Buyer
        $x->startElement('cac:AccountingCustomerParty');
        $x->startElement('cac:Party');
        if ($buyer) {
            $this->address($x, $buyer->street_name ?? null, $buyer->building_no ?? null, $buyer->additional_no ?? null, $buyer->city?->name_ar ?? null, $buyer->postal_code ?? null, $buyer->district?->name_ar ?? null);
            if (filled($buyer->vat_no)) {
                $this->taxScheme($x, (string) $buyer->vat_no);
            }
            $x->startElement('cac:PartyLegalEntity');
            $x->writeElement('cbc:RegistrationName', (string) $buyer->name_ar);
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();

        $x->startElement('cac:Delivery');
        $x->writeElement('cbc:ActualDeliveryDate', $doc->date->format('Y-m-d'));
        $x->endElement();

        $x->startElement('cac:PaymentMeans');
        $x->writeElement('cbc:PaymentMeansCode', match (true) {
            (float) $doc->card_amount > 0 => '48',
            $doc->payment_method === 'cash' || $type?->isPos() || $type?->isCash() => '10',
            default => '30',
        });
        if ($isReturn || $isDebit) {
            // BR-KSA-17: reason for issuing the credit / debit note
            $x->writeElement('cbc:InstructionNote', mb_substr($reason, 0, 1000));
        }
        $x->endElement();

        // Document-level allowance, one per VAT category it applies to.
        foreach ($b['groups'] as $group) {
            if ($group['allowance'] <= 0) {
                continue;
            }
            $x->startElement('cac:AllowanceCharge');
            $x->writeElement('cbc:ChargeIndicator', 'false');
            $x->writeElement('cbc:AllowanceChargeReason', 'discount');
            $this->amount($x, 'cbc:Amount', $group['allowance']);
            $this->taxCategory($x, $group['category'], withReason: false);
            $x->endElement();
        }

        // Tax totals: once without, once with the category breakdown.
        $x->startElement('cac:TaxTotal');
        $this->amount($x, 'cbc:TaxAmount', $b['tax_total']);
        $x->endElement();

        $x->startElement('cac:TaxTotal');
        $this->amount($x, 'cbc:TaxAmount', $b['tax_total']);
        foreach ($b['groups'] as $group) {
            $x->startElement('cac:TaxSubtotal');
            $this->amount($x, 'cbc:TaxableAmount', $group['taxable']);
            $this->amount($x, 'cbc:TaxAmount', $group['tax']);
            $this->taxCategory($x, $group['category'], withReason: true);
            $x->endElement();
        }
        $x->endElement();

        $x->startElement('cac:LegalMonetaryTotal');
        $this->amount($x, 'cbc:LineExtensionAmount', $b['line_total']);
        $this->amount($x, 'cbc:TaxExclusiveAmount', $b['tax_exclusive']);
        $this->amount($x, 'cbc:TaxInclusiveAmount', $b['tax_inclusive']);
        $this->amount($x, 'cbc:AllowanceTotalAmount', $b['allowance_total']);
        $this->amount($x, 'cbc:PrepaidAmount', 0);
        $this->amount($x, 'cbc:PayableAmount', $b['tax_inclusive']);
        $x->endElement();

        foreach ($doc->lines->values() as $i => $line) {
            $l = $b['lines'][$i];
            $x->startElement('cac:InvoiceLine');
            $x->writeElement('cbc:ID', (string) ($i + 1));
            $x->startElement('cbc:InvoicedQuantity');
            $x->writeAttribute('unitCode', 'PCE');
            $x->text(number_format((float) $line->quantity, 6, '.', ''));
            $x->endElement();
            $this->amount($x, 'cbc:LineExtensionAmount', $l['net']);
            $x->startElement('cac:TaxTotal');
            $this->amount($x, 'cbc:TaxAmount', $l['tax']);
            $this->amount($x, 'cbc:RoundingAmount', round($l['net'] + $l['tax'], 2));
            $x->endElement();
            $x->startElement('cac:Item');
            $x->writeElement('cbc:Name', (string) ($line->item?->name_ar ?? '-'));
            $x->startElement('cac:ClassifiedTaxCategory');
            $x->writeElement('cbc:ID', $l['category']['category']);
            $x->writeElement('cbc:Percent', number_format($l['category']['rate'], 2, '.', ''));
            $x->startElement('cac:TaxScheme');
            $x->writeElement('cbc:ID', 'VAT');
            $x->endElement();
            $x->endElement();
            $x->endElement();
            // Net price × quantity = line net (BR-KSA-EN16931-11); the line discount is the price allowance.
            $x->startElement('cac:Price');
            $this->amount($x, 'cbc:PriceAmount', $l['net_price'], 6);
            if ($l['unit_allowance'] > 0) {
                $x->startElement('cac:AllowanceCharge');
                $x->writeElement('cbc:ChargeIndicator', 'false');
                $x->writeElement('cbc:AllowanceChargeReason', 'discount');
                $this->amount($x, 'cbc:Amount', $l['unit_allowance'], 6);
                $this->amount($x, 'cbc:BaseAmount', $l['price'], 6);
                $x->endElement();
            }
            $x->endElement();
            $x->endElement();
        }

        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    /** XAdES-BES skeleton (values are filled by ZatcaXml::sign()). */
    protected function signatureExtension(\XMLWriter $x): void
    {
        $x->startElement('ext:UBLExtensions');
        $x->startElement('ext:UBLExtension');
        $x->writeElement('ext:ExtensionURI', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $x->startElement('ext:ExtensionContent');
        $x->startElement('sig:UBLDocumentSignatures');
        $x->writeAttribute('xmlns:sig', self::NS['sig']);
        $x->writeAttribute('xmlns:sac', self::NS['sac']);
        $x->writeAttribute('xmlns:sbc', self::NS['sbc']);
        $x->startElement('sac:SignatureInformation');
        $x->writeElement('cbc:ID', 'urn:oasis:names:specification:ubl:signature:1');
        $x->writeElement('sbc:ReferencedSignatureID', 'urn:oasis:names:specification:ubl:signature:Invoice');
        $x->startElement('ds:Signature');
        $x->writeAttribute('xmlns:ds', self::NS['ds']);
        $x->writeAttribute('Id', 'signature');

        $x->startElement('ds:SignedInfo');
        $this->algorithm($x, 'ds:CanonicalizationMethod', 'http://www.w3.org/2006/12/xml-c14n11');
        $this->algorithm($x, 'ds:SignatureMethod', 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256');
        $x->startElement('ds:Reference');
        $x->writeAttribute('Id', 'invoiceSignedData');
        $x->writeAttribute('URI', '');
        $x->startElement('ds:Transforms');
        foreach (['not(//ancestor-or-self::ext:UBLExtensions)', 'not(//ancestor-or-self::cac:Signature)', "not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])"] as $xpath) {
            $x->startElement('ds:Transform');
            $x->writeAttribute('Algorithm', 'http://www.w3.org/TR/1999/REC-xpath-19991116');
            $x->writeElement('ds:XPath', $xpath);
            $x->endElement();
        }
        $this->algorithm($x, 'ds:Transform', 'http://www.w3.org/2006/12/xml-c14n11');
        $x->endElement();
        $this->algorithm($x, 'ds:DigestMethod', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $x->writeElement('ds:DigestValue', '');
        $x->endElement();
        $x->startElement('ds:Reference');
        $x->writeAttribute('Type', 'http://www.w3.org/2000/09/xmldsig#SignatureProperties');
        $x->writeAttribute('URI', '#xadesSignedProperties');
        $this->algorithm($x, 'ds:DigestMethod', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $x->writeElement('ds:DigestValue', '');
        $x->endElement();
        $x->endElement();

        $x->writeElement('ds:SignatureValue', '');
        $x->startElement('ds:KeyInfo');
        $x->startElement('ds:X509Data');
        $x->writeElement('ds:X509Certificate', '');
        $x->endElement();
        $x->endElement();

        $x->startElement('ds:Object');
        $x->startElement('xades:QualifyingProperties');
        $x->writeAttribute('xmlns:xades', self::NS['xades']);
        $x->writeAttribute('Target', 'signature');
        $x->startElement('xades:SignedProperties');
        $x->writeAttribute('Id', 'xadesSignedProperties');
        $x->startElement('xades:SignedSignatureProperties');
        $x->writeElement('xades:SigningTime', '');
        $x->startElement('xades:SigningCertificate');
        $x->startElement('xades:Cert');
        $x->startElement('xades:CertDigest');
        $this->algorithm($x, 'ds:DigestMethod', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $x->writeElement('ds:DigestValue', '');
        $x->endElement();
        $x->startElement('xades:IssuerSerial');
        $x->writeElement('ds:X509IssuerName', '');
        $x->writeElement('ds:X509SerialNumber', '');
        $x->endElement();
        $x->endElement();
        $x->endElement();
        $x->endElement();
        $x->endElement();
        $x->endElement();
        $x->endElement();

        $x->endElement(); // ds:Signature
        $x->endElement(); // sac:SignatureInformation
        $x->endElement(); // sig:UBLDocumentSignatures
        $x->endElement(); // ext:ExtensionContent
        $x->endElement(); // ext:UBLExtension
        $x->endElement(); // ext:UBLExtensions
    }

    protected function algorithm(\XMLWriter $x, string $element, string $algorithm): void
    {
        $x->startElement($element);
        $x->writeAttribute('Algorithm', $algorithm);
        $x->endElement();
    }

    protected function additionalRef(\XMLWriter $x, string $id, ?string $attachment, ?string $uuid): void
    {
        $x->startElement('cac:AdditionalDocumentReference');
        $x->writeElement('cbc:ID', $id);
        if ($uuid !== null) {
            $x->writeElement('cbc:UUID', $uuid);
        }
        if ($attachment !== null) {
            $x->startElement('cac:Attachment');
            $x->startElement('cbc:EmbeddedDocumentBinaryObject');
            $x->writeAttribute('mimeCode', 'text/plain');
            $x->text($attachment);
            $x->endElement();
            $x->endElement();
        }
        $x->endElement();
    }

    protected function address(\XMLWriter $x, ?string $street, ?string $building, ?string $additional, ?string $city, ?string $postal, ?string $district): void
    {
        $x->startElement('cac:PostalAddress');
        $x->writeElement('cbc:StreetName', (string) $street);
        $x->writeElement('cbc:BuildingNumber', (string) $building);
        if ($additional) {
            $x->writeElement('cbc:PlotIdentification', $additional);
        }
        $x->writeElement('cbc:CitySubdivisionName', (string) $district);
        $x->writeElement('cbc:CityName', (string) $city);
        $x->writeElement('cbc:PostalZone', (string) $postal);
        $x->startElement('cac:Country');
        $x->writeElement('cbc:IdentificationCode', 'SA');
        $x->endElement();
        $x->endElement();
    }

    protected function taxScheme(\XMLWriter $x, string $vat): void
    {
        $x->startElement('cac:PartyTaxScheme');
        $x->writeElement('cbc:CompanyID', $vat);
        $x->startElement('cac:TaxScheme');
        $x->writeElement('cbc:ID', 'VAT');
        $x->endElement();
        $x->endElement();
    }

    /** @param  array{category: string, rate: float, code: ?string, reason: ?string}  $category */
    protected function taxCategory(\XMLWriter $x, array $category, bool $withReason): void
    {
        $x->startElement('cac:TaxCategory');
        $x->writeElement('cbc:ID', $category['category']);
        $x->writeElement('cbc:Percent', number_format($category['rate'], 2, '.', ''));
        if ($withReason && $category['code']) {
            $x->writeElement('cbc:TaxExemptionReasonCode', $category['code']);
            $x->writeElement('cbc:TaxExemptionReason', (string) $category['reason']);
        }
        $x->startElement('cac:TaxScheme');
        $x->writeElement('cbc:ID', 'VAT');
        $x->endElement();
        $x->endElement();
    }

    protected function amount(\XMLWriter $x, string $name, mixed $value, int $precision = 2): void
    {
        $x->startElement($name);
        $x->writeAttribute('currencyID', 'SAR');
        $x->text(number_format((float) $value, $precision, '.', ''));
        $x->endElement();
    }
}
