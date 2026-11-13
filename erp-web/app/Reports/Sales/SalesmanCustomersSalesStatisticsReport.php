<?php

namespace App\Reports\Sales;

use App\Models\Customer;
use App\Models\Salesman;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;
use Illuminate\Support\Facades\DB;

/**
 * Salesman Customers Sales Statistics (إحصائيات مبيعات عملاء المندوب): per salesman, one row per customer with
 * invoices, sales, returns, net sales (excl. VAT), VAT and total, and a subtotal per salesman. The salesman is the one
 * on the document, or the customer's salesman when the document has none.
 * Filters: Branches, Salesman, Customer Group, From*, To*.
 */
class SalesmanCustomersSalesStatisticsReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'salesman-customers-sales-statistics';

    protected static string $title = 'Salesman Customers Sales Statistics';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            ReportFields::salesman(),
            ReportFields::customerGroup(),
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
        $isReturn = SalesQuery::isReturn();
        $salesmanKey = 'COALESCE(d.salesman_id, cu.salesman_id)';

        $rows = SalesQuery::documents(array_diff_key($filters, ['salesman_id' => true]))
            ->join('customers as cu', 'cu.id', '=', 'd.customer_id')
            ->when($filters['salesman_id'] ?? null, fn ($q, $id) => $q->whereRaw("{$salesmanKey} = ?", [$id]))
            ->whereRaw("{$salesmanKey} IS NOT NULL")
            ->groupBy(DB::raw($salesmanKey), 'd.customer_id')
            ->selectRaw("{$salesmanKey} as salesman_key, d.customer_id")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 0 ELSE 1 END) as invoices")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 0 ELSE d.net_total END) as sales")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN d.net_total ELSE 0 END) as returns")
            ->selectRaw('SUM(('.SalesQuery::sign().') * d.tax_total) as vat')
            ->selectRaw('SUM('.SalesQuery::docAmount(true).') as total')
            ->get();

        $salesmen = Salesman::query()->whereKey($rows->pluck('salesman_key')->unique()->all())->orderBy('code')->get();
        $customers = Customer::query()->whereKey($rows->pluck('customer_id')->unique()->all())->get()->keyBy('id');
        $bySalesman = $rows->groupBy('salesman_key');
        $keys = ['invoices', 'sales', 'returns', 'net', 'vat', 'total'];

        $result = $this->result(SalesQuery::amountNote([]))
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->columns([
                ReportColumn::text('code', 'Customer Code'),
                ReportColumn::text('name', 'Customer'),
                ReportColumn::number('invoices', 'Invoices'),
                ReportColumn::money('sales', 'Sales'),
                ReportColumn::money('returns', 'Returns'),
                ReportColumn::money('net', 'Net Sales'),
                ReportColumn::money('vat', 'VAT'),
                ReportColumn::money('total', 'Grand Total'),
            ]);

        foreach ($salesmen as $salesman) {
            $result->addGroup($salesman->label);
            $cells = [];
            foreach ($bySalesman->get($salesman->id, collect())->sortBy(fn ($r) => $customers->get($r->customer_id)?->code) as $r) {
                $customer = $customers->get($r->customer_id);
                $cells[] = $cell = [
                    'code' => $customer?->code,
                    'name' => $customer?->name,
                    'invoices' => (int) $r->invoices,
                    'sales' => round((float) $r->sales, 2),
                    'returns' => round((float) $r->returns, 2),
                    'net' => round((float) $r->sales - (float) $r->returns, 2),
                    'vat' => round((float) $r->vat, 2),
                    'total' => round((float) $r->total, 2),
                ];
                $result->addRow($cell);
            }
            $result->addSubtotal(['name' => static::t('Salesman Total')] + ReportTotals::sum($cells, $keys));
        }

        return ReportTotals::footer($result, $keys, 'name');
    }
}
