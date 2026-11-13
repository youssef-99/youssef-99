<?php

namespace App\Reports\Support;

use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * Opening / period movement per account for a period, rolled up the chart of accounts
 * (a parent's figures are the sum of its descendants).
 */
class AccountBalances
{
    /** @var Collection<int, Account> */
    public Collection $accounts;

    /** @var array<int, array{opening: float, debit: float, credit: float, closing: float}> */
    public array $totals = [];

    public function __construct(?string $from, string $to, ?int $branchId = null)
    {
        $this->accounts = Ledger::accounts();
        $direct = Ledger::summary(Ledger::query($branchId), 'l.account_id', $from, $to);

        foreach ($direct as $accountId => $row) {
            $account = $this->accounts->get((int) $accountId);
            while ($account) {
                $current = $this->totals[$account->id] ?? ['opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing' => 0.0];
                foreach ($current as $k => $v) {
                    $current[$k] = round($v + $row[$k], 2);
                }
                $this->totals[$account->id] = $current;
                $account = $account->parent_id ? $this->accounts->get($account->parent_id) : null;
            }
        }
    }

    /** @return array{opening: float, debit: float, credit: float, closing: float} */
    public function for(int $accountId): array
    {
        return $this->totals[$accountId] ?? ['opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing' => 0.0];
    }

    /**
     * Accounts that make up a complete trial balance at a level: every account at that level,
     * plus leaf accounts that sit higher in the tree.
     *
     * @return Collection<int, Account>
     */
    public function atLevel(int $level): Collection
    {
        return $this->accounts
            ->filter(fn (Account $a) => $a->level == $level || ($a->level < $level && $a->is_postable))
            ->sortBy('number', SORT_STRING)
            ->values();
    }

    /**
     * Net balance by financial-statement sign: debit-nature items (assets, expenses) as debit - credit,
     * credit-nature items (liabilities, equity, revenue) as credit - debit.
     */
    public static function signed(float $net, string $balanceSheetItem): float
    {
        return in_array($balanceSheetItem, ['liabilities', 'equity', 'revenue'], true) ? round(-$net, 2) : round($net, 2);
    }
}
