<?php

namespace App\Reports\FinalAccounts;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\IncomeSections;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;

/**
 * Income Statement (قائمة الدخل): Net Sales, Cost of Sales, Gross Profit, Operating Expenses,
 * Other Revenue, Net Profit, from period movements on revenue and expense accounts.
 * Filters: Branch, From*, To*.
 */
class IncomeListReport extends Report
{
    protected static string $group = 'final-accounts';

    protected static string $slug = 'income-list';

    protected static string $title = 'Income Statement';

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
        return ['from' => ReportFields::fiscalYearStart(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->columns([
                ReportColumn::text('number', 'No.'),
                ReportColumn::text('name', 'Description'),
                ReportColumn::money('amount', 'Amount'),
            ]);

        $this->build($result, ['amount' => [$filters['from'], $filters['to']]], $filters['branch_id'] ?? null);

        return $result;
    }

    /**
     * Fill the statement with one money column per period.
     *
     * @param  array<string, array{0: string, 1: string}>  $periods  column key => [from, to]
     * @return array<string, float> net profit per column
     */
    protected function build(ReportResult $result, array $periods, ?int $branchId, ?string $totalKey = null): array
    {
        $sections = IncomeSections::split(Ledger::accounts());
        $movement = [];
        foreach ($periods as $key => [$from, $to]) {
            $movement[$key] = Ledger::summary(Ledger::query($branchId), 'l.account_id', $from, $to);
        }

        $keys = array_keys($periods);
        $sectionTotals = [];
        $render = function (string $section) use ($result, $sections, $movement, $keys, $totalKey, &$sectionTotals): void {
            $sign = IncomeSections::sign($section);
            $sum = array_fill_keys($keys, 0.0);
            $rows = [];
            foreach ($sections[$section] as $account) {
                $cells = ['number' => $account->number, 'name' => $account->name];
                $nonZero = false;
                foreach ($keys as $key) {
                    $t = $movement[$key][$account->id] ?? null;
                    $value = $t ? round($sign * ($t['debit'] - $t['credit']), 2) : 0.0;
                    $cells[$key] = $value;
                    $sum[$key] = round($sum[$key] + $value, 2);
                    $nonZero = $nonZero || $value != 0.0;
                }
                if ($totalKey) {
                    $cells[$totalKey] = round(array_sum(array_intersect_key($cells, array_flip($keys))), 2);
                }
                if ($nonZero) {
                    $rows[] = $cells;
                }
            }
            $result->addGroup(static::t(IncomeSections::SECTIONS[$section]));
            foreach ($rows as $cells) {
                $result->addRow($cells);
            }
            $result->addSubtotal(['name' => static::t('Total :name', ['name' => static::t(IncomeSections::SECTIONS[$section])])] + $this->withTotal($sum, $totalKey));
            $sectionTotals[$section] = $sum;
        };

        $render('sales');
        $render('cogs');
        $gross = [];
        foreach ($keys as $key) {
            $gross[$key] = round($sectionTotals['sales'][$key] - $sectionTotals['cogs'][$key], 2);
        }
        $result->addSubtotal(['name' => static::t('Gross Profit')] + $this->withTotal($gross, $totalKey));

        $render('expenses');
        $render('other_revenue');

        $net = [];
        foreach ($keys as $key) {
            $net[$key] = round($gross[$key] - $sectionTotals['expenses'][$key] + $sectionTotals['other_revenue'][$key], 2);
        }
        $result->addTotal(['name' => static::t('Net Profit (Loss)')] + $this->withTotal($net, $totalKey));

        return $net;
    }

    /**
     * @param  array<string, float>  $values
     * @return array<string, float>
     */
    protected function withTotal(array $values, ?string $totalKey): array
    {
        return $totalKey ? $values + [$totalKey => round(array_sum($values), 2)] : $values;
    }
}
