<?php

namespace App\Reports\Accounts;

use App\Models\Account;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\StatementBuilder;

/**
 * Accounts Statement (كشف حساب للحسابات) — the reference report.
 * Filters (all required): From Account, From Date (fiscal-year start), To Date (today).
 * A parent account includes all of its descendants (the account column is then shown).
 */
class AccountStatementReport extends Report
{
    protected static string $group = 'accounts';

    protected static string $slug = 'account-statement';

    protected static string $title = 'Accounts Statement';

    public function filters(): array
    {
        return [
            ReportFields::account('account_id', 'From Account')->required(),
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
        $ids = Ledger::descendantIds($account->id);

        $result = ReportResult::make(static::t('Account Statement'))
            ->period($filters['from'], $filters['to'])
            ->info('Account', $account->number.' - '.$account->name);

        StatementBuilder::fill($result, Ledger::forAccounts($ids), $filters['from'], $filters['to'], showAccount: count($ids) > 1);

        return $result;
    }
}
