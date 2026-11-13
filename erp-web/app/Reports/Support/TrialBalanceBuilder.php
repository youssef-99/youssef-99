<?php

namespace App\Reports\Support;

use App\Reports\ReportColumn;
use App\Reports\ReportResult;

/**
 * Trial balance layout (ميزان المراجعة): No., Description, Opening Balance Dr/Cr (رصيد أول المدة),
 * Movement Dr/Cr (الحركة), Closing Balance Dr/Cr (رصيد آخر المدة), with a Total footer.
 * Opening and closing are shown net (on one side); movement shows gross period debit and credit.
 */
class TrialBalanceBuilder
{
    /** @return array<int, ReportColumn> */
    public static function columns(string $numberLabel = 'No.', string $nameLabel = 'Description'): array
    {
        return [
            ReportColumn::text('number', $numberLabel),
            ReportColumn::text('name', $nameLabel),
            ReportColumn::money('opening_debit', 'Debit', 'Opening Balance'),
            ReportColumn::money('opening_credit', 'Credit', 'Opening Balance'),
            ReportColumn::money('debit', 'Debit', 'Movement'),
            ReportColumn::money('credit', 'Credit', 'Movement'),
            ReportColumn::money('closing_debit', 'Debit', 'Closing Balance'),
            ReportColumn::money('closing_credit', 'Credit', 'Closing Balance'),
        ];
    }

    /**
     * @param  iterable<array{number: string|null, name: string, opening: float, debit: float, credit: float}>  $rows
     * @return array<string, float> column totals
     */
    public static function fill(ReportResult $result, iterable $rows, bool $skipZero = true, string $numberLabel = 'No.', string $nameLabel = 'Description'): array
    {
        $result->columns(static::columns($numberLabel, $nameLabel))->landscape();
        $totals = array_fill_keys(['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'], 0.0);

        foreach ($rows as $row) {
            $cells = static::cells($row);
            if ($skipZero && array_sum(array_slice($cells, 2)) == 0.0) {
                continue;
            }
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + $cells[$key], 2);
            }
            $result->addRow($cells);
        }

        $result->addTotal(['name' => ReportLang::get('Total')] + $totals);

        return $totals;
    }

    /**
     * @param  array{number: string|null, name: string, opening: float, debit: float, credit: float}  $row
     * @return array<string, mixed>
     */
    public static function cells(array $row): array
    {
        [$openDebit, $openCredit] = Ledger::split($row['opening']);
        [$closeDebit, $closeCredit] = Ledger::split($row['opening'] + $row['debit'] - $row['credit']);

        return [
            'number' => $row['number'],
            'name' => $row['name'],
            'opening_debit' => $openDebit,
            'opening_credit' => $openCredit,
            'debit' => round($row['debit'], 2),
            'credit' => round($row['credit'], 2),
            'closing_debit' => $closeDebit,
            'closing_credit' => $closeCredit,
        ];
    }
}
