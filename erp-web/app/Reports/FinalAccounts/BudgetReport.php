<?php

namespace App\Reports\FinalAccounts;

use App\Models\Account;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\AccountBalances;
use App\Reports\Support\ReportFields;

/**
 * Balance Sheet (الميزانية العمومية) as of a date, by balance-sheet item (بند الميزانية):
 * assets (debit - credit), liabilities and equity (credit - debit), accounts rolled up to the chosen level.
 * The period's net profit (revenue - expenses not yet closed) is added to equity, so
 * Total Assets = Total Liabilities and Equity.
 */
class BudgetReport extends Report
{
    protected static string $group = 'final-accounts';

    protected static string $slug = 'budget';

    protected static string $title = 'Balance Sheet';

    public function filters(): array
    {
        return [
            ReportFields::toDate('to', 'As Of Date')->required(),
            ReportFields::level()->required(),
            ReportFields::branch(),
        ];
    }

    public function defaults(): array
    {
        return ['to' => ReportFields::today(), 'level' => 3];
    }

    public function run(array $filters): ReportResult
    {
        $level = (int) $filters['level'];
        $balances = new AccountBalances(null, $filters['to'], $filters['branch_id'] ?? null);

        $result = $this->result()
            ->info('As Of Date', ReportColumn::formatDate($filters['to']))
            ->info('Level No.', (string) $level)
            ->columns([
                ReportColumn::text('number', 'No.'),
                ReportColumn::text('name', 'Description'),
                ReportColumn::money('amount', 'Amount'),
            ]);

        $netProfit = static::netProfit($balances);
        $sections = ['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'];
        $totals = [];

        foreach ($sections as $item => $label) {
            $result->addGroup(static::t($label));
            $sum = 0.0;
            foreach ($balances->atLevel($level)->where('balance_sheet_item', $item) as $account) {
                $amount = AccountBalances::signed($balances->for($account->id)['closing'], $item);
                if ($amount == 0.0) {
                    continue;
                }
                $sum += $amount;
                $result->addRow(['number' => $account->number, 'name' => $account->name, 'amount' => $amount]);
            }
            if ($item === 'equity') {
                $result->addRow(['name' => static::t($netProfit >= 0 ? 'Net Profit for the Period' : 'Net Loss for the Period'), 'amount' => $netProfit]);
                $sum += $netProfit;
            }
            $totals[$item] = round($sum, 2);
            $result->addSubtotal(['name' => static::t('Total :name', ['name' => static::t($label)]), 'amount' => $totals[$item]]);
        }

        $result->addTotal(['name' => static::t('Total Assets'), 'amount' => $totals['assets']]);
        $result->addTotal(['name' => static::t('Total Liabilities and Equity'), 'amount' => round($totals['liabilities'] + $totals['equity'], 2)]);

        return $result;
    }

    /** Revenue - expenses over all root accounts of those items. */
    public static function netProfit(AccountBalances $balances, string $key = 'closing'): float
    {
        $net = 0.0;
        foreach ($balances->accounts->whereNull('parent_id') as $root) {
            /** @var Account $root */
            if (in_array($root->balance_sheet_item, ['revenue', 'expenses'], true)) {
                $t = $balances->for($root->id);
                $value = $key === 'movement' ? $t['debit'] - $t['credit'] : $t[$key];
                $net += -$value;
            }
        }

        return round($net, 2);
    }
}
