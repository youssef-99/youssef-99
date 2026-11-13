<?php

namespace App\Enums;

/**
 * ZATCA VAT exemption / zero-rating reason codes (سبب الإعفاء الضريبي), required on items with 0% VAT.
 * Category: Z = zero rated, E = exempt, O = out of scope.
 */
enum TaxExemptionReason: string
{
    case Medicines = 'VATEX-SA-35';
    case Healthcare = 'VATEX-SA-HEA';
    case Education = 'VATEX-SA-EDU';
    case ExportGoods = 'VATEX-SA-32';
    case ExportServices = 'VATEX-SA-33';
    case InternationalGoodsTransport = 'VATEX-SA-34-1';
    case InternationalPassengerTransport = 'VATEX-SA-34-2';
    case PassengerTransportServices = 'VATEX-SA-34-3';
    case QualifyingTransportMeans = 'VATEX-SA-34-4';
    case TransportRelatedServices = 'VATEX-SA-34-5';
    case QualifyingMetals = 'VATEX-SA-36';
    case MilitaryGoods = 'VATEX-SA-MLTRY';
    case FinancialServices = 'VATEX-SA-29';
    case LifeInsurance = 'VATEX-SA-29-7';
    case RealEstate = 'VATEX-SA-30';
    case OutOfScope = 'VATEX-SA-OOS';

    public function label(): string
    {
        return $this->value.' - '.__(match ($this) {
            self::Medicines => 'Medicines and medical equipment',
            self::Healthcare => 'Private healthcare to citizens',
            self::Education => 'Private education to citizens',
            self::ExportGoods => 'Export of goods',
            self::ExportServices => 'Export of services',
            self::InternationalGoodsTransport => 'International transport of goods',
            self::InternationalPassengerTransport => 'International transport of passengers',
            self::PassengerTransportServices => 'Services incidental to international passenger transport',
            self::QualifyingTransportMeans => 'Supply of a qualifying means of transport',
            self::TransportRelatedServices => 'Services relating to goods or passenger transportation',
            self::QualifyingMetals => 'Qualifying metals',
            self::MilitaryGoods => 'Qualified military goods',
            self::FinancialServices => 'Financial services',
            self::LifeInsurance => 'Life insurance services',
            self::RealEstate => 'Real estate transactions',
            self::OutOfScope => 'Not subject to VAT',
        });
    }

    /** ZATCA tax category code (Z, E or O). */
    public function taxCategory(): string
    {
        return match ($this) {
            self::FinancialServices, self::LifeInsurance, self::RealEstate => 'E',
            self::OutOfScope => 'O',
            default => 'Z',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
