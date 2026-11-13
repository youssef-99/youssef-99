<?php

namespace App\Reports\Sales;

use App\Models\User;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesAggregates;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;

/**
 * User Sales Analysis (تحليل مبيعات المستخدمين): per user who created the posted sales documents — invoices, returns,
 * sales, returns value, net sales (excl. VAT), VAT, total, cost, profit and margin.
 * Filters: Branches, User, From*, To*.
 */
class UserSalesAnalysisReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'user-sales-analysis';

    protected static string $title = 'User Sales Analysis';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            TradeFields::user(),
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
        $stats = SalesAggregates::byColumn($filters, 'd.created_by');
        $users = User::query()->whereKey($stats->keys()->filter()->all())->get()->keyBy('id');

        $result = $this->result(SalesQuery::amountNote([]))
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape()
            ->columns([
                ReportColumn::text('user', 'User'),
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

        foreach ($stats->sortBy(fn ($s) => $users->get($s->group_key)?->name ?? '') as $s) {
            $result->addRow([
                'user' => $users->get($s->group_key)?->name ?? '-',
                'invoices' => $s->invoices,
                'returns_count' => $s->returns_count,
                'sales' => $s->sales,
                'returns' => $s->returns,
                'net' => $s->net,
                'vat' => $s->vat,
                'total' => $s->total,
                'cost' => $s->cost,
                'profit' => $s->profit,
                'margin' => $s->margin,
            ]);
        }

        return ReportTotals::footer($result, ['invoices', 'returns_count', 'sales', 'returns', 'net', 'vat', 'total', 'cost', 'profit'], 'user', [
            'margin' => SalesQuery::margin($result->sum('profit'), $result->sum('net')),
        ]);
    }
}
