<?php

namespace App\Reports\Vat;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\VatDocuments;

/**
 * VAT Transactions (جميع حركات الضريبة): every posted VAT-bearing document — sales, POS, cash sales,
 * purchases, foreign and cash purchases and their returns — with taxable amount and VAT.
 * Sales and purchases are grouped with subtotals; the footer shows net VAT due (output - input).
 */
class AllVatReport extends Report
{
    protected static string $group = 'vat-reports';

    protected static string $slug = 'all-vat';

    protected static string $title = 'VAT Transactions';

    public function filters(): array
    {
        return [
            ReportFields::select('side', 'Transactions', ['sales' => static::t('Sales'), 'purchases' => static::t('Purchases')]),
            ReportFields::branch(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $docs = VatDocuments::get($filters['from'], $filters['to'], $filters['branch_id'] ?? null, $filters['side'] ?? null);

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->landscape()
            ->columns([
                ReportColumn::text('type', 'Document Type'),
                ReportColumn::text('number', 'No.'),
                ReportColumn::date('date', 'Date'),
                ReportColumn::text('party', 'Customer / Supplier'),
                ReportColumn::text('vat_no', 'VAT No.'),
                ReportColumn::money('taxable', 'Taxable Amount'),
                ReportColumn::money('vat', 'VAT Amount'),
                ReportColumn::money('total', 'Total Including VAT'),
            ]);

        $sums = [];
        foreach (['sales' => 'Sales (Output VAT)', 'purchases' => 'Purchases (Input VAT)'] as $side => $label) {
            $group = $docs->where('side', $side);
            $sums[$side] = ['taxable' => round($group->sum('taxable'), 2), 'vat' => round($group->sum('vat'), 2), 'total' => round($group->sum('total'), 2)];
            if ($group->isEmpty()) {
                continue;
            }
            $result->addGroup(static::t($label));
            foreach ($group as $doc) {
                $result->addRow([
                    'type' => $doc['type_label'],
                    'number' => $doc['number'],
                    'date' => $doc['date'],
                    'party' => $doc['party'],
                    'vat_no' => $doc['vat_no'],
                    'taxable' => $doc['taxable'],
                    'vat' => $doc['vat'],
                    'total' => $doc['total'],
                ]);
            }
            $result->addSubtotal(['party' => static::t('Total :name', ['name' => static::t($label)])] + $sums[$side]);
        }

        $result->addTotal(['party' => static::t('Output VAT'), 'vat' => $sums['sales']['vat']]);
        $result->addTotal(['party' => static::t('Input VAT'), 'vat' => $sums['purchases']['vat']]);
        $result->addTotal(['party' => static::t('Net VAT Due'), 'vat' => round($sums['sales']['vat'] - $sums['purchases']['vat'], 2)]);

        return $result;
    }
}
