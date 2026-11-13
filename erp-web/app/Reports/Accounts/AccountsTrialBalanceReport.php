<?php

namespace App\Reports\Accounts;

use App\Models\Account;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\AccountBalances;
use App\Reports\Support\ReportFields;
use App\Reports\Support\TrialBalanceBuilder;
use Filament\Forms\Components\Toggle;

/**
 * Subsidiary Ledger Trial Balance (ميزان مراجعة للأستاذ المساعد).
 * Filters: Level No.*, From*, To*, Branch. Balances are rolled up to the chosen level.
 */
class AccountsTrialBalanceReport extends Report
{
    protected static string $group = 'accounts';

    protected static string $slug = 'accounts-trial-balance';

    protected static string $title = 'Subsidiary Ledger Trial Balance';

    public function filters(): array
    {
        return [
            ReportFields::level()->required(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
            ReportFields::branch(),
            Toggle::make('show_zero')->label(static::t('Show Zero Balances')),
        ];
    }

    public function defaults(): array
    {
        return [
            'level' => (int) (Account::query()->max('level') ?: 1),
            'from' => ReportFields::fiscalYearStart(),
            'to' => ReportFields::today(),
            'show_zero' => false,
        ];
    }

    public function run(array $filters): ReportResult
    {
        $level = (int) $filters['level'];
        $balances = new AccountBalances($filters['from'], $filters['to'], $filters['branch_id'] ?? null);

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Level No.', (string) $level);

        $rows = $balances->atLevel($level)->map(fn (Account $a) => ['number' => $a->number, 'name' => $a->name] + $balances->for($a->id));

        TrialBalanceBuilder::fill($result, $rows, skipZero: ! filter_var($filters['show_zero'] ?? false, FILTER_VALIDATE_BOOLEAN));

        return $result;
    }
}
