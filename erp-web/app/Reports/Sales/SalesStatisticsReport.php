<?php

namespace App\Reports\Sales;

use App\Models\Branch;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesAggregates;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;
use Illuminate\Support\Carbon;

/**
 * Sales Statistics (إحصائيات المبيعات): invoices, returns, net sales (excl. VAT), VAT, total, cost, profit and margin
 * grouped by day, month or branch. Filters: Branches, Report Type* (by day / by month / by branch), From*, To*.
 * Aggregated in SQL per (date, branch) and rolled up here so month grouping works on every database.
 */
class SalesStatisticsReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'sales-statistics';

    protected static string $title = 'Sales Statistics';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            TradeFields::choice('report_type', 'Report Type', ['day' => 'By Day', 'month' => 'By Month', 'branch' => 'By Branch'])->required(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['report_type' => 'day', 'from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $mode = $filters['report_type'] ?? 'day';
        $rows = SalesAggregates::byColumn($filters, $mode === 'branch' ? 'd.branch_id' : 'd.date');
        $branches = $mode === 'branch' ? Branch::query()->get()->keyBy('id') : collect();

        $keys = ['invoices', 'returns_count', 'sales', 'returns', 'net', 'vat', 'total', 'cost', 'profit'];
        $groups = [];
        foreach ($rows as $r) {
            $key = match ($mode) {
                'branch' => (string) $r->group_key,
                'month' => Carbon::parse($r->group_key)->format('Y-m'),
                default => Carbon::parse($r->group_key)->toDateString(),
            };
            $groups[$key] ??= array_fill_keys($keys, 0.0);
            foreach ($keys as $k) {
                $groups[$key][$k] += (float) $r->{$k};
            }
        }
        ksort($groups);

        $result = $this->result(static::t(match ($mode) {
            'branch' => 'By Branch',
            'month' => 'By Month',
            default => 'By Day',
        }))
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape()
            ->columns([
                $mode === 'day' ? ReportColumn::date('period', 'Date') : ReportColumn::text('period', $mode === 'branch' ? 'Branch' : 'Month'),
                ReportColumn::number('invoices', 'Invoices'),
                ReportColumn::number('returns_count', 'Returns Count'),
                ReportColumn::money('sales', 'Sales'),
                ReportColumn::money('returns', 'Returns'),
                ReportColumn::money('net', 'Net Sales'),
                ReportColumn::money('vat', 'VAT'),
                ReportColumn::money('total', 'Grand Total'),
                ReportColumn::money('cost', 'Cost'),
                ReportColumn::money('profit', 'Profit'),
                ReportColumn::number('margin', 'Margin %', 2),
            ]);

        foreach ($groups as $key => $g) {
            $g = array_map(fn (float $v) => round($v, 2), $g);
            $result->addRow([
                'period' => $mode === 'branch' ? ($branches->get((int) $key)?->label ?? '-') : $key,
                'margin' => SalesQuery::margin($g['profit'], $g['net']),
            ] + $g);
        }

        return ReportTotals::footer($result, $keys, 'period', [
            'margin' => SalesQuery::margin($result->sum('profit'), $result->sum('net')),
        ]);
    }
}
