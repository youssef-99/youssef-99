<?php

namespace App\Services\Zatca;

use App\Enums\SalesDocumentType;
use App\Models\Company;
use App\Models\SalesDocument;

/**
 * Builds a ZATCA UBL 2.1 invoice / credit note XML (without the UBLExtensions signature block).
 */
class UblInvoiceBuilder
{
    public function build(SalesDocument $doc): string
    {
        $type = SalesDocumentType::from($doc->type);
        $company = $doc->branch?->company ?? Company::query()->first();
        $isReturn = $type->isReturn();
        $standard = $doc->zatca_invoice_type === 'standard';
        $typeCode = $isReturn ? '381' : '388';
        // name attribute: 0100000 = standard tax invoice, 0200000 = simplified
        $subtype = $standard ? '0100000' : '0200000';
        $buyer = $doc->customer ?? $doc->cashCustomer;
        $time = $doc->time ?: ($doc->created_at?->format('H:i:s') ?? '00:00:00');

        $x = new \XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('Invoice');
        $x->writeAttribute('xmlns', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $x->writeAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $x->writeAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $x->writeAttribute('xmlns:ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');

        $x->writeElement('cbc:ProfileID', 'reporting:1.0');
        $x->writeElement('cbc:ID', $doc->number);
        $x->writeElement('cbc:UUID', $doc->uuid);
        $x->writeElement('cbc:IssueDate', $doc->date->format('Y-m-d'));
        $x->writeElement('cbc:IssueTime', $time);
        $x->startElement('cbc:InvoiceTypeCode');
        $x->writeAttribute('name', $subtype);
        $x->text($typeCode);
        $x->endElement();
        $x->writeElement('cbc:DocumentCurrencyCode', 'SAR');
        $x->writeElement('cbc:TaxCurrencyCode', 'SAR');

        if ($isReturn && $doc->originalDocument) {
            $x->startElement('cac:BillingReference');
            $x->startElement('cac:InvoiceDocumentReference');
            $x->writeElement('cbc:ID', $doc->originalDocument->number);
            $x->endElement();
            $x->endElement();
        }

        $this->additionalRef($x, 'ICV', null, (string) $doc->zatca_icv);
        $this->additionalRef($x, 'PIH', $doc->zatca_previous_hash, null);

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
                $this->taxScheme($x, $buyer->vat_no);
            }
            $x->startElement('cac:PartyLegalEntity');
            $x->writeElement('cbc:RegistrationName', $buyer->name_ar);
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
            in_array($doc->payment_method, ['cash'], true) || $type->isPos() || $type->isCash() => '10',
            default => '30',
        });
        if ($isReturn) {
            $x->writeElement('cbc:InstructionNote', $doc->notes ?: 'Return');
        }
        $x->endElement();

        if ((float) $doc->additional_discount > 0) {
            $x->startElement('cac:AllowanceCharge');
            $x->writeElement('cbc:ChargeIndicator', 'false');
            $x->writeElement('cbc:AllowanceChargeReason', 'discount');
            $this->amount($x, 'cbc:Amount', $doc->additional_discount);
            $x->startElement('cac:TaxCategory');
            $x->writeElement('cbc:ID', 'S');
            $x->writeElement('cbc:Percent', '15.00');
            $x->startElement('cac:TaxScheme');
            $x->writeElement('cbc:ID', 'VAT');
            $x->endElement();
            $x->endElement();
            $x->endElement();
        }

        // Tax totals (once in SAR, once with subtotals)
        $x->startElement('cac:TaxTotal');
        $this->amount($x, 'cbc:TaxAmount', $doc->tax_total);
        $x->endElement();

        $x->startElement('cac:TaxTotal');
        $this->amount($x, 'cbc:TaxAmount', $doc->tax_total);
        $factor = (float) $doc->gross_total - (float) $doc->items_discount > 0
            ? (float) $doc->net_total / ((float) $doc->gross_total - (float) $doc->items_discount) : 1;
        foreach ($doc->lines->groupBy(fn ($l) => number_format((float) $l->tax_percent, 2, '.', '')) as $rate => $group) {
            $taxable = round($group->sum(fn ($l) => (float) $l->gross - (float) $l->discount) * $factor, 2);
            $x->startElement('cac:TaxSubtotal');
            $this->amount($x, 'cbc:TaxableAmount', $taxable);
            $this->amount($x, 'cbc:TaxAmount', round($taxable * (float) $rate / 100, 2));
            $this->taxCategory($x, (float) $rate, $group->first()?->item?->tax_exemption_reason);
            $x->endElement();
        }
        $x->endElement();

        $x->startElement('cac:LegalMonetaryTotal');
        $this->amount($x, 'cbc:LineExtensionAmount', (float) $doc->gross_total - (float) $doc->items_discount);
        $this->amount($x, 'cbc:TaxExclusiveAmount', $doc->net_total);
        $this->amount($x, 'cbc:TaxInclusiveAmount', $doc->grand_total);
        $this->amount($x, 'cbc:AllowanceTotalAmount', $doc->additional_discount);
        $this->amount($x, 'cbc:PrepaidAmount', 0);
        $this->amount($x, 'cbc:PayableAmount', $doc->grand_total);
        $x->endElement();

        foreach ($doc->lines->values() as $i => $line) {
            $lineNet = (float) $line->gross - (float) $line->discount;
            $x->startElement('cac:InvoiceLine');
            $x->writeElement('cbc:ID', (string) ($i + 1));
            $x->startElement('cbc:InvoicedQuantity');
            $x->writeAttribute('unitCode', 'PCE');
            $x->text(number_format((float) $line->quantity, 6, '.', ''));
            $x->endElement();
            $this->amount($x, 'cbc:LineExtensionAmount', $lineNet);
            $x->startElement('cac:TaxTotal');
            $this->amount($x, 'cbc:TaxAmount', $line->tax);
            $this->amount($x, 'cbc:RoundingAmount', $lineNet + (float) $line->tax);
            $x->endElement();
            $x->startElement('cac:Item');
            $x->writeElement('cbc:Name', (string) $line->item?->name_ar);
            $x->startElement('cac:ClassifiedTaxCategory');
            $x->writeElement('cbc:ID', (float) $line->tax_percent > 0 ? 'S' : ($line->item?->tax_exemption_reason ? 'E' : 'Z'));
            $x->writeElement('cbc:Percent', number_format((float) $line->tax_percent, 2, '.', ''));
            $x->startElement('cac:TaxScheme');
            $x->writeElement('cbc:ID', 'VAT');
            $x->endElement();
            $x->endElement();
            $x->endElement();
            $x->startElement('cac:Price');
            $this->amount($x, 'cbc:PriceAmount', $line->unit_price, 4);
            if ((float) $line->discount > 0) {
                $x->startElement('cac:AllowanceCharge');
                $x->writeElement('cbc:ChargeIndicator', 'false');
                $x->writeElement('cbc:AllowanceChargeReason', 'discount');
                $this->amount($x, 'cbc:Amount', (float) $line->quantity > 0 ? (float) $line->discount / (float) $line->quantity : 0, 4);
                $x->endElement();
            }
            $x->endElement();
            $x->endElement();
        }

        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
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

    protected function taxCategory(\XMLWriter $x, float $rate, ?string $exemption): void
    {
        $x->startElement('cac:TaxCategory');
        $x->writeElement('cbc:ID', $rate > 0 ? 'S' : ($exemption ? 'E' : 'Z'));
        $x->writeElement('cbc:Percent', number_format($rate, 2, '.', ''));
        if ($rate == 0.0 && $exemption) {
            $x->writeElement('cbc:TaxExemptionReasonCode', $exemption);
            $x->writeElement('cbc:TaxExemptionReason', $exemption);
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
