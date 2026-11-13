<?php

namespace App\Reports\Support;

use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use Illuminate\Database\Query\Builder;

/**
 * Builds a ledger statement (كشف حساب): opening balance row, one row per posted line with a running
 * balance, and the footer rows Total (الإجمالي) and Movement During Period (الحركة خلال فترة).
 * Used by the account, customer, supplier, cost center, salesman and letter-of-credit statements.
 */
class StatementBuilder
{
    /**
     * @return array<int, ReportColumn>
     */
    public static function columns(bool $showAccount = false): array
    {
        return array_values(array_filter([
            ReportColumn::date('date', 'Date'),
            ReportColumn::text('entry', 'Entry'),
            ReportColumn::text('number', 'No.'),
            $showAccount ? ReportColumn::text('account', 'Account') : null,
            ReportColumn::text('description', 'Description'),
            ReportColumn::money('debit', 'Debit'),
            ReportColumn::money('credit', 'Credit'),
            ReportColumn::money('balance', 'Balance'),
        ]));
    }

    /**
     * Fill $result with the statement of the lines selected by $query between $from and $to.
     *
     * @return array{opening: float, debit: float, credit: float, closing: float}
     */
    public static function fill(ReportResult $result, Builder $query, string $from, string $to, bool $showAccount = false, bool $withFooter = true): array
    {
        if ($result->columns === []) {
            $result->columns(static::columns($showAccount));
        }

        $opening = Ledger::openingBalance($query, $from);
        [$openDebit, $openCredit] = Ledger::split($opening);
        $result->addRow([
            'date' => $from,
            'description' => ReportLang::get('Opening Balance'),
            'debit' => $openDebit,
            'credit' => $openCredit,
            'balance' => $opening,
        ], 'opening');

        $balance = $opening;
        $debit = $credit = 0.0;
        foreach (Ledger::movements($query, $from, $to) as $line) {
            $balance = round($balance + $line->debit - $line->credit, 2);
            $debit += $line->debit;
            $credit += $line->credit;
            $result->addRow([
                'date' => $line->date,
                'entry' => Ledger::entryTypeLabel($line->entry_type),
                'number' => $line->entry_number,
                'account' => $line->account_number.' - '.$line->account_name,
                'description' => $line->description ?: $line->entry_description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'balance' => $balance,
            ]);
        }

        $debit = round($debit, 2);
        $credit = round($credit, 2);
        $totals = ['opening' => $opening, 'debit' => $debit, 'credit' => $credit, 'closing' => $balance];

        if ($withFooter) {
            static::footer($result, $totals);
        }

        return $totals;
    }

    /** @param  array{opening: float, debit: float, credit: float, closing: float}  $totals */
    public static function footer(ReportResult $result, array $totals, bool $asSubtotal = false): void
    {
        [$openDebit, $openCredit] = Ledger::split($totals['opening']);
        $total = [
            'description' => ReportLang::get('Total'),
            'debit' => round($openDebit + $totals['debit'], 2),
            'credit' => round($openCredit + $totals['credit'], 2),
            'balance' => $totals['closing'],
        ];
        $movement = [
            'description' => ReportLang::get('Movement During Period'),
            'debit' => $totals['debit'],
            'credit' => $totals['credit'],
            'balance' => round($totals['debit'] - $totals['credit'], 2),
        ];

        if ($asSubtotal) {
            $result->addSubtotal($total);
            $result->addSubtotal($movement);

            return;
        }

        $result->addTotal($total);
        $result->addTotal($movement);
    }
}
