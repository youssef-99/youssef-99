<?php

namespace App\Reports\Customers;

use App\Models\Customer;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\PartyLedger;
use App\Reports\Support\ReportFields;

/**
 * Customers Trial Balance (ميزان مراجعة العملاء): opening, movement and closing per customer.
 * Filters: Customer Group, Salesman, Branch, From*, To*.
 */
class CustomerTrialBalanceReport extends Report
{
    protected static string $group = 'customers';

    protected static string $slug = 'customer-trial-balance';

    protected static string $title = 'Customers Trial Balance';

    public function filters(): array
    {
        return [
            ReportFields::customerGroup(),
            ReportFields::salesman(),
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
        $customers = Customer::query()
            ->when($filters['customer_group_id'] ?? null, fn ($q, $id) => $q->where('customer_group_id', $id))
            ->when($filters['salesman_id'] ?? null, fn ($q, $id) => $q->where('salesman_id', $id))
            ->orderBy('code')
            ->get();

        return PartyLedger::trialBalance($this->result(), $customers, $filters['from'], $filters['to'], $filters['branch_id'] ?? null);
    }
}
