<?php

namespace App\Reports\CostCenters;

use App\Models\Account;
use App\Models\CostCenter;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\StatementBuilder;

/**
 * Account Statement by Cost Center (كشف حساب حسب مركز التكلفة): the account's lines grouped by cost center,
 * each group with its own opening balance, running balance and subtotals. Filters: Account*, Cost Center, Branch, From*, To*.
 */
class AccountCostCenterStatementReport extends Report
{
    protected static string $group = 'cost-centers';

    protected static string $slug = 'account-cost-center-statement';

    protected static string $title = 'Account Statement by Cost Center';

    public function filters(): array
    {
        return [
            ReportFields::account()->required(),
            ReportFields::costCenter(),
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
        $account = Account::query()->findOrFail($filters['account_id']);
        $base = Ledger::forAccounts(Ledger::descendantIds($account->id), $filters['branch_id'] ?? null);

        $centers = CostCenter::query()->orderBy('code')
            ->when($filters['cost_center_id'] ?? null, fn ($q, $id) => $q->whereIn('id', CostCenterStatementReport::descendantIds((int) $id)))
            ->get();

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Account', $account->number.' - '.$account->name);

        $grand = ['opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing' => 0.0];
        $groups = $centers->map(fn (CostCenter $c) => [$c->label, (clone $base)->where('l.cost_center_id', $c->id)]);
        if (! ($filters['cost_center_id'] ?? null)) {
            $groups->push([static::t('Without Cost Center'), (clone $base)->whereNull('l.cost_center_id')]);
        }

        foreach ($groups as [$label, $query]) {
            if (! (clone $query)->where('e.date', '<', Ledger::nextDay($filters['to']))->exists()) {
                continue;
            }
            $result->addGroup($label);
            $totals = StatementBuilder::fill($result, $query, $filters['from'], $filters['to'], withFooter: false);
            StatementBuilder::footer($result, $totals, asSubtotal: true);
            foreach ($grand as $k => $v) {
                $grand[$k] = round($v + $totals[$k], 2);
            }
        }

        if ($result->columns === []) {
            $result->columns(StatementBuilder::columns());
        }
        StatementBuilder::footer($result, $grand);

        return $result;
    }
}
