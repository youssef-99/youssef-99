<?php

namespace App\Reports\Customers;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\StatementBuilder;

/**
 * Customer Group Statement (كشف حساب مجموعة عملاء): the statement of every customer in the group,
 * one section per customer (opening, lines, subtotals), and a grand total.
 * Filters: Customer Group*, Branch, From*, To*.
 */
class CustomersGroupStatementReport extends Report
{
    protected static string $group = 'customers';

    protected static string $slug = 'customers-group-statement';

    protected static string $title = 'Customer Group Statement';

    public function filters(): array
    {
        return [
            ReportFields::customerGroup()->required(),
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
        $group = CustomerGroup::query()->findOrFail($filters['customer_group_id']);
        $customers = Customer::query()->where('customer_group_id', $group->id)->whereNotNull('account_id')->orderBy('code')->get();

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Customer Group', $group->label)
            ->columns(StatementBuilder::columns());

        $grand = ['opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing' => 0.0];
        foreach ($customers as $customer) {
            $query = Ledger::forAccounts([$customer->account_id], $filters['branch_id'] ?? null);
            if (! (clone $query)->where('e.date', '<', Ledger::nextDay($filters['to']))->exists()) {
                continue;
            }
            $result->addGroup($customer->label);
            $totals = StatementBuilder::fill($result, $query, $filters['from'], $filters['to'], withFooter: false);
            StatementBuilder::footer($result, $totals, asSubtotal: true);
            foreach ($grand as $k => $v) {
                $grand[$k] = round($v + $totals[$k], 2);
            }
        }

        StatementBuilder::footer($result, $grand);

        return $result;
    }
}
