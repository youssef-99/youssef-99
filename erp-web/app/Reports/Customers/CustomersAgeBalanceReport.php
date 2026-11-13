<?php

namespace App\Reports\Customers;

use App\Models\Customer;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\PartyLedger;
use App\Reports\Support\ReportFields;

/**
 * Customers Debt Ageing (أعمار ديون العملاء): each customer's balance at To Date split into age buckets
 * (0-30 / 31-60 / 61-90 / 91-120 / over 120 with the default 30-day period).
 * Filters: Customer, Customer Group, Salesman, Branch, From*, To*, Debt Ageing* (days per bucket).
 */
class CustomersAgeBalanceReport extends Report
{
    protected static string $group = 'customers';

    protected static string $slug = 'customers-age-balance';

    protected static string $title = 'Customers Debt Ageing';

    public function filters(): array
    {
        return [
            ReportFields::customer(),
            ReportFields::customerGroup(),
            ReportFields::salesman(),
            ReportFields::branch(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
            ReportFields::integer('ageing_days', 'Debt Ageing')->required()->suffix(static::t('days')),
        ];
    }

    public function defaults(): array
    {
        return ['from' => ReportFields::fiscalYearStart(), 'to' => ReportFields::today(), 'ageing_days' => 30];
    }

    public function run(array $filters): ReportResult
    {
        $customers = Customer::query()
            ->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['customer_group_id'] ?? null, fn ($q, $id) => $q->where('customer_group_id', $id))
            ->when($filters['salesman_id'] ?? null, fn ($q, $id) => $q->where('salesman_id', $id))
            ->orderBy('code')
            ->get();

        return PartyLedger::aging($this->result(), $customers, $filters['from'], $filters['to'], (int) $filters['ageing_days'], 1, $filters['branch_id'] ?? null);
    }
}
