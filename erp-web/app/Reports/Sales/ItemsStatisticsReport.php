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
 * Items Statistics (إحصائيات الأصناف): per item — quantity sold, returned, free and net, sales, returns and net sales
 * value, number of invoices and average selling price. Posted sales of every kind; returns subtract.
 * Filters: Branches, Item Category, Item, Customer, Amounts (excl. / incl. VAT), From*, To*.
 */
class ItemsStatisticsReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'items-statistics';

    protected static string $title = 'Items Statistics';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            TradeFields::category(),
            TradeFields::item(),
            ReportFields::customer(),
            TradeFields::taxMode(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['tax_mode' => 'without', 'from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $result = $this->result(SalesQuery::amountNote($filters))
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape()
            ->columns([
                ReportColumn::text('code', 'Item Code'),
                ReportColumn::text('name', 'Item Name'),
                ReportColumn::text('unit', 'Unit'),
                ReportColumn::number('qty_sold', 'Sold Qty', 3),
                ReportColumn::number('qty_returned', 'Returned Qty', 3),
                ReportColumn::number('free_qty', 'Free Qty', 3),
                ReportColumn::number('net_qty', 'Net Qty', 3),
                ReportColumn::money('sales', 'Sales'),
                ReportColumn::money('returns', 'Returns'),
                ReportColumn::money('net_sales', 'Net Sales'),
                ReportColumn::number('documents', 'Invoices'),
                ReportColumn::number('average_price', 'Average Price', 4),
            ]);

        foreach (SalesAggregates::byItem($filters, SalesQuery::withTax($filters)) as $r) {
            $result->addRow([
                'code' => $r->code,
                'name' => $r->name,
                'unit' => $r->unit,
                'qty_sold' => $r->qty_sold,
                'qty_returned' => $r->qty_returned,
                'free_qty' => $r->free_qty,
                'net_qty' => $r->net_qty,
                'sales' => $r->sales,
                'returns' => $r->returns,
                'net_sales' => $r->net_sales,
                'documents' => (int) $r->documents,
                'average_price' => $r->net_qty != 0.0 ? round($r->net_sales / $r->net_qty, 4) : 0.0,
            ]);
        }

        return ReportTotals::footer($result, ['qty_sold', 'qty_returned', 'free_qty', 'net_qty', 'sales', 'returns', 'net_sales'], 'name');
    }
}
