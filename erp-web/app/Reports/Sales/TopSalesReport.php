<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesAggregates;
use App\Reports\Support\TradeFields;

/**
 * Top Sales (الأكثر مبيعاً): the best-selling items by net quantity or by net sales value (excl. VAT), with their
 * share of the total. Filters: Branches, No. of Items, Report Type* (by quantity / by value), Supplier, From*, To*.
 */
class TopSalesReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'top-sales';

    protected static string $title = 'Top Sales';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            ReportFields::integer('limit', 'No. of Items')->maxValue(1000),
            TradeFields::choice('report_type', 'Report Type', ['quantity' => 'By Quantity', 'value' => 'By Value'])->required(),
            ReportFields::supplier(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['limit' => 10, 'report_type' => 'quantity', 'from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $byValue = ($filters['report_type'] ?? 'quantity') === 'value';
        $limit = max(1, (int) ($filters['limit'] ?? 10));
        $items = SalesAggregates::byItem($filters);
        $metric = $byValue ? 'net_excl' : 'net_qty';
        $grand = (float) $items->sum($metric);

        $top = $items->sort(fn (object $a, object $b) => [$b->{$metric}, $b->net_excl, $a->code] <=> [$a->{$metric}, $a->net_excl, $b->code])
            ->take($limit)
            ->values();

        $result = $this->result(static::t($byValue ? 'By Value' : 'By Quantity'))
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->columns([
                ReportColumn::number('rank', 'Rank'),
                ReportColumn::text('code', 'Item Code'),
                ReportColumn::text('name', 'Item Name'),
                ReportColumn::number('quantity', 'Quantity', 3),
                ReportColumn::money('sales', 'Net Sales'),
                ReportColumn::number('share', 'Share %', 2),
            ]);

        foreach ($top as $i => $r) {
            $result->addRow([
                'rank' => $i + 1,
                'code' => $r->code,
                'name' => $r->name,
                'quantity' => $r->net_qty,
                'sales' => $r->net_excl,
                'share' => $grand != 0.0 ? round((float) $r->{$metric} / $grand * 100, 2) : 0.0,
            ]);
        }

        return ReportTotals::footer($result, ['quantity', 'sales', 'share'], 'name');
    }
}
