<?php

namespace App\Reports\Sales;

use App\Models\Customer;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesAggregates;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;

/**
 * Customer Sales Statistics (إحصائيات مبيعات العملاء): per credit customer — invoices, returns, net sales (excl. VAT),
 * VAT, total, cost, profit, margin, average invoice and last invoice date.
 * Filters: Branches, Customer Group, Customer, Salesman, From*, To*.
 */
class CustomerSalesStatisticsReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'customer-sales-statistics';

    protected static string $title = 'Customer Sales Statistics';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            ReportFields::customerGroup(),
            ReportFields::customer(),
            ReportFields::salesman(),
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
        $stats = SalesAggregates::byColumn($filters, 'd.customer_id');
        $customers = Customer::query()->whereKey($stats->keys()->filter()->all())->orderBy('code')->get();

        $result = $this->result(SalesQuery::amountNote([]))
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape()
            ->columns([
                ReportColumn::text('code', 'Customer Code'),
                ReportColumn::text('name', 'Customer'),
                ReportColumn::number('invoices', 'Invoices'),
                ReportColumn::number('returns_count', 'Returns Count'),
                ReportColumn::money('net', 'Net Sales'),
                ReportColumn::money('vat', 'VAT'),
                ReportColumn::money('total', 'Grand Total'),
                ReportColumn::money('cost', 'Cost'),
                ReportColumn::money('profit', 'Profit'),
                ReportColumn::number('margin', 'Margin %', 2),
                ReportColumn::money('average', 'Average Invoice'),
                ReportColumn::date('last_date', 'Last Invoice Date'),
            ]);

        foreach ($customers as $customer) {
            $s = $stats->get($customer->id);
            $result->addRow([
                'code' => $customer->code,
                'name' => $customer->name,
                'invoices' => $s->invoices,
                'returns_count' => $s->returns_count,
                'net' => $s->net,
                'vat' => $s->vat,
                'total' => $s->total,
                'cost' => $s->cost,
                'profit' => $s->profit,
                'margin' => $s->margin,
                'average' => $s->invoices > 0 ? round($s->net / $s->invoices, 2) : 0.0,
                'last_date' => $s->last_date,
            ]);
        }

        return ReportTotals::footer($result, ['invoices', 'returns_count', 'net', 'vat', 'total', 'cost', 'profit'], 'name', [
            'margin' => SalesQuery::margin($result->sum('profit'), $result->sum('net')),
        ]);
    }
}
