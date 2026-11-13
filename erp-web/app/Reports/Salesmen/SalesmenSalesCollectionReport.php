<?php

namespace App\Reports\Salesmen;

use App\Models\Salesman;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Salesmen Sales and Collections (مبيعات وتحصيلات المندوبين), per salesman:
 *  - Sales / Returns: posted sales, POS and cash sales invoices / returns of the salesman (grand total incl. VAT)
 *  - Collections: credits on customer accounts from receipts, notices and cheques (not sales, returns or opening
 *    entries), tagged with the salesman or, when untagged, on customers assigned to him
 *  - Commission: collections x the salesman's commission %.
 * Filters: Salesman, Branch, From*, To*.
 */
class SalesmenSalesCollectionReport extends Report
{
    protected static string $group = 'salesmen';

    protected static string $slug = 'salesmen-sales-collection';

    protected static string $title = 'Salesmen Sales and Collections';

    public function filters(): array
    {
        return [
            ReportFields::salesman(),
            ReportFields::branch(),
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
        $branchId = $filters['branch_id'] ?? null;
        $salesmen = Salesman::query()->when($filters['salesman_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('code')->get();

        $sales = DB::table('sales_documents')
            ->where('is_posted', true)
            ->whereNotNull('salesman_id')
            ->whereIn('type', ['sales_invoice', 'sales_return', 'pos_invoice', 'pos_return', 'cash_sales_invoice', 'cash_sales_return'])
            ->where('date', '>=', Ledger::date($filters['from']))
            ->where('date', '<', Ledger::nextDay($filters['to']))
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->groupBy('salesman_id')
            ->select('salesman_id')
            ->selectRaw("SUM(CASE WHEN type IN ('sales_return','pos_return','cash_sales_return') THEN 0 ELSE grand_total END) as sales")
            ->selectRaw("SUM(CASE WHEN type IN ('sales_return','pos_return','cash_sales_return') THEN grand_total ELSE 0 END) as returns")
            ->get()
            ->keyBy('salesman_id');

        $collections = Ledger::between(Ledger::query($branchId), $filters['from'], $filters['to'])
            ->join('accounts as acc', 'acc.id', '=', 'l.account_id')
            ->leftJoin('customers as c', 'c.account_id', '=', 'l.account_id')
            ->where('acc.type', 'customer')
            ->where('l.credit', '>', 0)
            ->whereNotIn('e.entry_type', ['sales', 'sales_return', 'pos', 'opening', 'credit_note', 'debit_note'])
            ->selectRaw('COALESCE(l.salesman_id, c.salesman_id) as salesman_key, SUM(l.credit) as amount')
            ->groupByRaw('COALESCE(l.salesman_id, c.salesman_id)')
            ->get()
            ->keyBy('salesman_key');

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->landscape()
            ->columns([
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('name', 'Salesman'),
                ReportColumn::money('sales', 'Sales'),
                ReportColumn::money('returns', 'Returns'),
                ReportColumn::money('net_sales', 'Net Sales'),
                ReportColumn::money('collections', 'Collections'),
                ReportColumn::number('commission_percent', 'Commission %', 2),
                ReportColumn::money('commission', 'Commission'),
            ]);

        foreach ($salesmen as $salesman) {
            $s = (float) ($sales[$salesman->id]->sales ?? 0);
            $r = (float) ($sales[$salesman->id]->returns ?? 0);
            $c = (float) ($collections[$salesman->id]->amount ?? 0);
            if ($s == 0.0 && $r == 0.0 && $c == 0.0) {
                continue;
            }
            $pct = (float) $salesman->commission_percent;
            $result->addRow([
                'code' => $salesman->code,
                'name' => $salesman->name,
                'sales' => round($s, 2),
                'returns' => round($r, 2),
                'net_sales' => round($s - $r, 2),
                'collections' => round($c, 2),
                'commission_percent' => $pct,
                'commission' => round($c * $pct / 100, 2),
            ]);
        }

        $total = ['name' => static::t('Total')];
        foreach (['sales', 'returns', 'net_sales', 'collections', 'commission'] as $key) {
            $total[$key] = $result->sum($key);
        }
        $result->addTotal($total);

        return $result;
    }
}
