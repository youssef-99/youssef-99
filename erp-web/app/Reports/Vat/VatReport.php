<?php

namespace App\Reports\Vat;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\VatDocuments;
use Illuminate\Support\Collection;

/**
 * VAT Return (إقرار ضريبة القيمة المضافة) laid out as the ZATCA return boxes:
 * 1-6 sales (standard, private healthcare / education to citizens, zero-rated, exports, exempt, total),
 * 7-12 purchases (standard, imports paid at customs, reverse charge, zero-rated, exempt, total),
 * 13 total VAT due, 14 corrections from previous periods, 15 VAT credit carried forward, 16 net VAT due.
 * Amount = invoices, Adjustment = returns (negative), VAT = output / input VAT of the category.
 */
class VatReport extends Report
{
    protected static string $group = 'vat-reports';

    protected static string $slug = 'vat';

    protected static string $title = 'VAT Return';

    public function filters(): array
    {
        return [
            ReportFields::branch(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['from' => today()->firstOfQuarter()->toDateString(), 'to' => today()->lastOfQuarter()->toDateString()];
    }

    public function run(array $filters): ReportResult
    {
        $docs = VatDocuments::get($filters['from'], $filters['to'], $filters['branch_id'] ?? null);
        $sales = $docs->where('side', 'sales');
        $purchases = $docs->where('side', 'purchases');

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->columns([
                ReportColumn::text('box', 'Box'),
                ReportColumn::text('description', 'Description'),
                ReportColumn::money('amount', 'Amount (SAR)'),
                ReportColumn::money('adjustment', 'Adjustment (SAR)'),
                ReportColumn::money('vat', 'VAT Amount (SAR)'),
            ]);

        $result->addGroup(static::t('VAT on Sales'));
        $salesBoxes = [
            '1' => ['standard', 'Standard rated sales'],
            '2' => ['health_edu', 'Private healthcare / private education sales to citizens'],
            '3' => ['zero', 'Zero rated domestic sales'],
            '4' => ['export', 'Exports'],
            '5' => ['exempt', 'Exempt sales'],
        ];
        $salesTotal = $this->boxes($result, $sales, $salesBoxes);
        $result->addSubtotal(['box' => '6', 'description' => static::t('Total sales')] + $salesTotal);

        $result->addGroup(static::t('VAT on Purchases'));
        $purchaseBoxes = [
            '7' => ['standard', 'Standard rated domestic purchases'],
            '8' => ['import', 'Imports subject to VAT paid at customs'],
            '9' => [null, 'Imports subject to VAT accounted for through reverse charge mechanism'],
            '10' => ['zero', 'Zero rated purchases'],
            '11' => ['exempt', 'Exempt purchases'],
        ];
        $purchaseTotal = $this->boxes($result, $purchases, $purchaseBoxes);
        $result->addSubtotal(['box' => '12', 'description' => static::t('Total purchases')] + $purchaseTotal);

        $due = round($salesTotal['vat'] - $purchaseTotal['vat'], 2);
        $result->addTotal(['box' => '13', 'description' => static::t('Total VAT due for current period'), 'vat' => $due]);
        $result->addTotal(['box' => '14', 'description' => static::t('Corrections from previous period (between SAR ±5,000)'), 'vat' => 0.0]);
        $result->addTotal(['box' => '15', 'description' => static::t('VAT credit carried forward from previous period(s)'), 'vat' => 0.0]);
        $result->addTotal(['box' => '16', 'description' => static::t('Net VAT due (or claimed)'), 'vat' => $due]);

        return $result;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $docs
     * @param  array<string, array{0: ?string, 1: string}>  $boxes
     * @return array{amount: float, adjustment: float, vat: float}
     */
    protected function boxes(ReportResult $result, Collection $docs, array $boxes): array
    {
        $total = ['amount' => 0.0, 'adjustment' => 0.0, 'vat' => 0.0];
        foreach ($boxes as $box => [$category, $label]) {
            $amount = $adjustment = $vat = 0.0;
            if ($category !== null) {
                foreach ($docs as $doc) {
                    $value = (float) $doc[$category];
                    $doc['sign'] < 0 ? $adjustment += $value : $amount += $value;
                    // All VAT on a document belongs to its standard-rated (or import) lines.
                    if (in_array($category, ['standard', 'import'], true) && ($category === 'import') === ($doc['type'] === 'foreign_purchase_invoice')) {
                        $vat += (float) $doc['vat'];
                    }
                }
            }
            $cells = ['amount' => round($amount, 2), 'adjustment' => round($adjustment, 2), 'vat' => round($vat, 2)];
            $result->addRow(['box' => $box, 'description' => static::t($label)] + $cells);
            foreach ($total as $k => $v) {
                $total[$k] = round($v + $cells[$k], 2);
            }
        }

        return $total;
    }
}
