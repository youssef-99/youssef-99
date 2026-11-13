<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesAggregates;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;

/**
 * Items Sales Analysis (تحليل مبيعات الأصناف): per item — net quantity, net sales (excl. VAT, after the document
 * additional discount), cost of goods sold (quantity incl. free × posted unit cost), profit and margin %.
 * Filters: Branches, Item Category, Item, Supplier, From*, To*.
 */
class ItemsSalesAnalysisReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'items-sales-analysis';

    protected static string $title = 'Items Sales Analysis';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            TradeFields::category(),
            TradeFields::item(),
            ReportFields::supplier(),
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
        $result = $this->result(SalesQuery::amountNote([]))
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->columns([
                ReportColumn::text('code', 'Item Code'),
                ReportColumn::text('name', 'Item Name'),
                ReportColumn::number('quantity', 'Quantity', 3),
                ReportColumn::money('sales', 'Net Sales'),
                ReportColumn::money('cost', 'Cost'),
                ReportColumn::money('profit', 'Profit'),
                ReportColumn::number('margin', 'Margin %', 2),
            ]);

        foreach (SalesAggregates::byItem($filters) as $r) {
            $result->addRow([
                'code' => $r->code,
                'name' => $r->name,
                'quantity' => $r->net_qty,
                'sales' => $r->net_excl,
                'cost' => $r->cost,
                'profit' => $r->profit,
                'margin' => SalesQuery::margin($r->profit, $r->net_excl),
            ]);
        }

        return ReportTotals::footer($result, ['quantity', 'sales', 'cost', 'profit'], 'name', [
            'margin' => SalesQuery::margin($result->sum('profit'), $result->sum('sales')),
        ]);
    }
}
