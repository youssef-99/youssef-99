<?php

namespace App\Reports\Support;

use App\Reports\ReportResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Statements, trial balances and debt ageing for parties that own a sub-ledger account
 * (customers, suppliers): each party is read through its `account_id`.
 */
class PartyLedger
{
    /**
     * Statement of one party.
     */
    public static function statement(ReportResult $result, Model $party, string $from, string $to, ?int $branchId = null): ReportResult
    {
        $result->period($from, $to)
            ->info('Name', $party->label)
            ->info('VAT No.', $party->vat_no ?? null);

        StatementBuilder::fill($result, Ledger::forAccounts(array_filter([$party->account_id]), $branchId), $from, $to);

        return $result;
    }

    /**
     * Trial balance with one row per party.
     *
     * @param  Collection<int, Model>  $parties
     */
    public static function trialBalance(ReportResult $result, Collection $parties, string $from, string $to, ?int $branchId = null, bool $skipZero = true): ReportResult
    {
        $accountIds = $parties->pluck('account_id')->filter()->all();
        $summary = Ledger::summary(Ledger::forAccounts($accountIds, $branchId), 'l.account_id', $from, $to);

        $rows = $parties->map(fn (Model $p) => ['number' => $p->code, 'name' => $p->name]
            + ($summary[$p->account_id] ?? ['opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0]));

        TrialBalanceBuilder::fill($result->period($from, $to), $rows, $skipZero, 'Code', 'Name');

        return $result;
    }

    /**
     * Debt ageing: balance as of $to, aged FIFO against the charges (customer debits / supplier credits) from $from to $to.
     *
     * @param  Collection<int, Model>  $parties
     * @param  int  $sign  +1 for customers (debit balance owed), -1 for suppliers (credit balance owed)
     */
    public static function aging(ReportResult $result, Collection $parties, string $from, string $to, int $days, int $sign, ?int $branchId = null): ReportResult
    {
        $result->period($from, $to)
            ->info('Debt Ageing', $days.' '.ReportLang::get('days'))
            ->landscape()
            ->columns(AgingBuilder::columns($days));

        $accountIds = $parties->pluck('account_id')->filter()->values()->all();
        $closing = Ledger::summary(Ledger::forAccounts($accountIds, $branchId), 'l.account_id', null, $to);
        $chargeColumn = $sign > 0 ? 'l.debit' : 'l.credit';
        $charges = Ledger::between(Ledger::forAccounts($accountIds, $branchId), $from, $to)
            ->where($chargeColumn, '>', 0)
            ->get(['l.account_id', 'e.date', "{$chargeColumn} as amount"])
            ->groupBy('account_id');

        $totals = [];
        foreach ($parties as $party) {
            $balance = round($sign * ($closing[$party->account_id]['closing'] ?? 0.0), 2);
            if ($balance == 0.0) {
                continue;
            }
            $list = $charges->get($party->account_id, collect())->map(fn ($c) => ['date' => (string) $c->date, 'amount' => (float) $c->amount])->all();
            $cells = ['code' => $party->code, 'name' => $party->name, 'balance' => $balance] + AgingBuilder::allocate($balance, $list, $to, $days);
            foreach ($cells as $key => $value) {
                if (is_float($value)) {
                    $totals[$key] = round(($totals[$key] ?? 0.0) + $value, 2);
                }
            }
            $result->addRow($cells);
        }

        $result->addTotal(['name' => ReportLang::get('Total')] + $totals);

        return $result;
    }
}
