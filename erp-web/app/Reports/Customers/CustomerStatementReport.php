<?php

namespace App\Reports\Customers;

use App\Models\Customer;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\PartyLedger;
use App\Reports\Support\ReportFields;

/**
 * Customer Statement (كشف حساب عميل): the customer's sub-ledger account with opening balance,
 * running balance, Total and Movement During Period. Filters: Customer*, Branch, From*, To*.
 */
class CustomerStatementReport extends Report
{
    protected static string $group = 'customers';

    protected static string $slug = 'customer-statement';

    protected static string $title = 'Customer Statement';

    public function filters(): array
    {
        return [
            ReportFields::customer()->required(),
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
        $customer = Customer::query()->findOrFail($filters['customer_id']);

        return PartyLedger::statement($this->result(), $customer, $filters['from'], $filters['to'], $filters['branch_id'] ?? null);
    }
}
