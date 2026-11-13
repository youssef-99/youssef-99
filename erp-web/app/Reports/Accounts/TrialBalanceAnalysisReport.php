<?php

namespace App\Reports\Accounts;

use App\Models\Account;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\AccountBalances;
use App\Reports\Support\ReportFields;

/**
 * Trial Balance Analysis (تحليل ميزان المراجعة): the chart of accounts as a tree down to the chosen level,
 * each account with opening balance, period debit / credit, net movement and closing balance
 * (balances are debit - credit). Parent accounts are shown as bold subtotal rows; the footer totals level 1.
 */
class TrialBalanceAnalysisReport extends Report
{
    protected static string $group = 'accounts';

    protected static string $slug = 'trial-balance-analysis';

    protected static string $title = 'Trial Balance Analysis';

    public function filters(): array
    {
        return [
            ReportFields::level()->required(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
            ReportFields::branch(),
        ];
    }

    public function defaults(): array
    {
        return ['level' => 3, 'from' => ReportFields::fiscalYearStart(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $level = (int) $filters['level'];
        $balances = new AccountBalances($filters['from'], $filters['to'], $filters['branch_id'] ?? null);

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Level No.', (string) $level)
            ->landscape()
            ->columns([
                ReportColumn::text('number', 'No.'),
                ReportColumn::text('name', 'Description'),
                ReportColumn::number('level', 'Level'),
                ReportColumn::money('opening', 'Opening Balance'),
                ReportColumn::money('debit', 'Debit', 'Movement'),
                ReportColumn::money('credit', 'Credit', 'Movement'),
                ReportColumn::money('net', 'Net Movement'),
                ReportColumn::money('closing', 'Closing Balance'),
            ]);

        $totals = ['opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing' => 0.0];

        foreach ($this->tree($balances, $level) as $account) {
            $t = $balances->for($account->id);
            if ($t['opening'] == 0.0 && $t['debit'] == 0.0 && $t['credit'] == 0.0) {
                continue;
            }
            $cells = [
                'number' => $account->number,
                'name' => str_repeat('  ', max(0, $account->level - 1)).$account->name,
                'level' => $account->level,
                'opening' => $t['opening'],
                'debit' => $t['debit'],
                'credit' => $t['credit'],
                'net' => round($t['debit'] - $t['credit'], 2),
                'closing' => $t['closing'],
            ];
            $isParent = ! $account->is_postable && $account->level < $level;
            $result->addRow($cells, $isParent ? 'subtotal' : 'row');

            if ($account->level == 1) {
                foreach ($totals as $k => $v) {
                    $totals[$k] = round($v + $t[$k], 2);
                }
            }
        }

        $result->addTotal([
            'name' => static::t('Total'),
            'opening' => $totals['opening'],
            'debit' => $totals['debit'],
            'credit' => $totals['credit'],
            'net' => round($totals['debit'] - $totals['credit'], 2),
            'closing' => $totals['closing'],
        ]);

        return $result;
    }

    /**
     * Accounts down to $level in tree (depth-first, by number) order.
     *
     * @return array<int, Account>
     */
    protected function tree(AccountBalances $balances, int $level): array
    {
        $children = $balances->accounts->sortBy('number', SORT_STRING)->groupBy(fn (Account $a) => (int) $a->parent_id);
        $out = [];
        $walk = function (int $parentId) use (&$walk, &$out, $children, $level): void {
            foreach ($children->get($parentId, collect()) as $account) {
                if ($account->level > $level) {
                    continue;
                }
                $out[] = $account;
                $walk($account->id);
            }
        };
        $walk(0);

        return $out;
    }
}
