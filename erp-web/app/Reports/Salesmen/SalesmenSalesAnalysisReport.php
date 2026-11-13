<?php

namespace App\Reports\Salesmen;

use App\Models\Salesman;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesAggregates;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;

/**
 * Salesmen Sales Analysis (تحليل مبيعات المندوبين): per salesman on the posted sales documents — invoices, returns,
 * sales, returns value, net sales (excl. VAT), cost, profit, margin and commission on net sales (salesman commission %).
 * Filters: Branches, Salesman, From*, To*.
 */
class SalesmenSalesAnalysisReport extends Report
{
    protected static string $group = 'salesmen';

    protected static string $slug = 'salesmen-sales-analysis';

    protected static string $title = 'Salesmen Sales Analysis';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            ReportFields::salesman(),
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
        $stats = SalesAggregates::byColumn($filters, 'd.salesman_id');
        $salesmen = Salesman::query()->whereKey($stats->keys()->filter()->all())->orderBy('code')->get();

        $result = $this->result(SalesQuery::amountNote([]))
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape()
            ->columns([
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('name', 'Salesman'),
                ReportColumn::number('invoices', 'Invoices'),
                ReportColumn::number('returns_count', 'Returns Count'),
                ReportColumn::money('sales', 'Sales'),
                ReportColumn::money('returns', 'Returns'),
                ReportColumn::money('net', 'Net Sales'),
                ReportColumn::money('cost', 'Cost'),
                ReportColumn::money('profit', 'Profit'),
                ReportColumn::number('margin', 'Margin %', 2),
                ReportColumn::number('commission_percent', 'Commission %', 2),
                ReportColumn::money('commission', 'Commission'),
            ]);

        foreach ($salesmen as $salesman) {
            $s = $stats->get($salesman->id);
            $pct = (float) $salesman->commission_percent;
            $result->addRow([
                'code' => $salesman->code,
                'name' => $salesman->name,
                'invoices' => $s->invoices,
                'returns_count' => $s->returns_count,
                'sales' => $s->sales,
                'returns' => $s->returns,
                'net' => $s->net,
                'cost' => $s->cost,
                'profit' => $s->profit,
                'margin' => $s->margin,
                'commission_percent' => $pct,
                'commission' => round($s->net * $pct / 100, 2),
            ]);
        }

        return ReportTotals::footer($result, ['invoices', 'returns_count', 'sales', 'returns', 'net', 'cost', 'profit', 'commission'], 'name', [
            'margin' => SalesQuery::margin($result->sum('profit'), $result->sum('net')),
        ]);
    }
}
