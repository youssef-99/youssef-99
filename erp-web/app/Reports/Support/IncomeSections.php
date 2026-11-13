<?php

namespace App\Reports\Support;

use App\Models\Account;
use App\Services\Settings;
use Illuminate\Support\Collection;

/**
 * Splits the postable revenue / expense accounts into income-statement sections:
 *  - sales:         revenue accounts under the level-2 group of the default Sales account
 *  - cogs:          expense accounts under the level-2 group of the default COGS account
 *  - expenses:      the other expense accounts
 *  - other_revenue: the other revenue accounts
 * Section amounts: revenue = credit - debit, expenses = debit - credit.
 */
class IncomeSections
{
    public const SECTIONS = [
        'sales' => 'Net Sales',
        'cogs' => 'Cost of Sales',
        'expenses' => 'Operating Expenses',
        'other_revenue' => 'Other Revenue',
    ];

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<string, Collection<int, Account>>
     */
    public static function split(Collection $accounts): array
    {
        $salesRoot = static::rootOf($accounts, Settings::account('sales'));
        $cogsRoot = static::rootOf($accounts, Settings::account('cogs'));

        $out = [];
        foreach (array_keys(static::SECTIONS) as $section) {
            $out[$section] = collect();
        }
        foreach ($accounts->where('is_postable', true)->sortBy('number', SORT_STRING) as $account) {
            $item = $account->balance_sheet_item;
            if (! in_array($item, ['revenue', 'expenses'], true)) {
                continue;
            }
            $ancestor = Ledger::ancestorAtLevel($accounts, $account->id, 2);
            $section = match (true) {
                $item === 'revenue' && $salesRoot && $ancestor?->id === $salesRoot => 'sales',
                $item === 'revenue' => 'other_revenue',
                $cogsRoot && $ancestor?->id === $cogsRoot => 'cogs',
                default => 'expenses',
            };
            $out[$section]->push($account);
        }

        return $out;
    }

    /** Sign for a section amount from a debit - credit net. */
    public static function sign(string $section): int
    {
        return in_array($section, ['sales', 'other_revenue'], true) ? -1 : 1;
    }

    /** @param  Collection<int, Account>  $accounts */
    protected static function rootOf(Collection $accounts, ?int $accountId): ?int
    {
        return $accountId ? Ledger::ancestorAtLevel($accounts, $accountId, 2)?->id : null;
    }
}
