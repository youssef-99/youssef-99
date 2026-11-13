<?php

namespace App\Services\Zatca;

/**
 * ZATCA VAT categories (UNCL5305 subset) and the official exemption reason codes / texts (KSA tax exemption list).
 *
 *  S = standard rate, Z = zero rated, E = exempt, O = out of scope.
 */
class ZatcaTaxCategory
{
    /** @var array<string, array{0: string, 1: string}> code => [category, reason text] */
    public const EXEMPTIONS = [
        'VATEX-SA-29' => ['E', 'Financial services mentioned in Article 29 of the VAT Regulations'],
        'VATEX-SA-29-7' => ['E', 'Life insurance services mentioned in Article 29 of the VAT Regulations'],
        'VATEX-SA-30' => ['E', 'Real estate transactions mentioned in Article 30 of the VAT Regulations'],
        'VATEX-SA-32' => ['Z', 'Export of goods'],
        'VATEX-SA-33' => ['Z', 'Export of services'],
        'VATEX-SA-34-1' => ['Z', 'The international transport of Goods'],
        'VATEX-SA-34-2' => ['Z', 'International transport of passengers'],
        'VATEX-SA-34-3' => ['Z', 'Services directly connected and incidental to a Supply of international passenger transport'],
        'VATEX-SA-34-4' => ['Z', 'Supply of a qualifying means of transport'],
        'VATEX-SA-34-5' => ['Z', 'Any services relating to Goods or passenger transportation, as defined in article twenty five of these Regulations'],
        'VATEX-SA-35' => ['Z', 'Medicines and medical equipment'],
        'VATEX-SA-36' => ['Z', 'Qualifying metals'],
        'VATEX-SA-EDU' => ['Z', 'Private education to citizen'],
        'VATEX-SA-HEA' => ['Z', 'Private healthcare to citizen'],
        'VATEX-SA-MLTRY' => ['Z', 'supply of qualified military goods'],
        'VATEX-SA-OOS' => ['O', 'Not subject to VAT'],
    ];

    /** Zero-rated pharmacy default when an item has 0% VAT but no exemption code. */
    public const DEFAULT_ZERO_CODE = 'VATEX-SA-35';

    /**
     * @return array{category: string, rate: float, code: ?string, reason: ?string}
     */
    public static function resolve(float $rate, ?string $exemptionCode): array
    {
        if ($rate > 0) {
            return ['category' => 'S', 'rate' => $rate, 'code' => null, 'reason' => null];
        }

        $code = strtoupper(trim((string) $exemptionCode));
        if (! isset(self::EXEMPTIONS[$code])) {
            $code = self::DEFAULT_ZERO_CODE;
        }

        [$category, $reason] = self::EXEMPTIONS[$code];

        return ['category' => $category, 'rate' => 0.0, 'code' => $code, 'reason' => $reason];
    }

    public static function key(array $category): string
    {
        return $category['category'].'|'.number_format($category['rate'], 2, '.', '').'|'.($category['code'] ?? '');
    }
}
