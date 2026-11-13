<?php

namespace App\Reports\Support;

use App\Models\Account;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queries over POSTED journal lines (`journal_entry_lines` l joined to `journal_entries` e).
 *
 * Dates are compared as `e.date >= from` and `e.date < (to + 1 day)` so the "to" day is included whatever
 * the driver stores in the date column (SQLite keeps "Y-m-d 00:00:00").
 * Balances are always debit - credit (positive = debit balance).
 */
class Ledger
{
    /** Base query over posted lines, optionally limited to a branch. */
    public static function query(?int $branchId = null): Builder
    {
        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.is_posted', true)
            ->when($branchId, fn (Builder $q) => $q->where('e.branch_id', $branchId));
    }

    /** @param  array<int, int>  $accountIds */
    public static function forAccounts(array $accountIds, ?int $branchId = null): Builder
    {
        return static::query($branchId)->whereIn('l.account_id', $accountIds ?: [0]);
    }

    public static function nextDay(string $date): string
    {
        return Carbon::parse($date)->addDay()->toDateString();
    }

    public static function date(string $date): string
    {
        return Carbon::parse($date)->toDateString();
    }

    public static function between(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('e.date', '>=', static::date($from)))
            ->when($to, fn (Builder $q) => $q->where('e.date', '<', static::nextDay($to)));
    }

    /** Net (debit - credit) of everything before $from. */
    public static function openingBalance(Builder $query, ?string $from): float
    {
        if (! $from) {
            return 0.0;
        }

        return round((float) (clone $query)->where('e.date', '<', static::date($from))->sum(DB::raw('l.debit - l.credit')), 2);
    }

    /** Net (debit - credit) up to and including $to. */
    public static function closingBalance(Builder $query, string $to): float
    {
        return round((float) (clone $query)->where('e.date', '<', static::nextDay($to))->sum(DB::raw('l.debit - l.credit')), 2);
    }

    /**
     * Lines in the period, oldest first, with entry columns.
     *
     * @return Collection<int, object>
     */
    public static function movements(Builder $query, ?string $from, ?string $to): Collection
    {
        return static::between(clone $query, $from, $to)
            ->leftJoin('accounts as acc', 'acc.id', '=', 'l.account_id')
            ->select([
                'l.id', 'l.account_id', 'l.debit', 'l.credit', 'l.description', 'l.cost_center_id', 'l.salesman_id',
                'l.accreditation_id', 'l.document_no', 'e.id as entry_id', 'e.date', 'e.number as entry_number',
                'e.entry_type', 'e.description as entry_description', 'e.branch_id', 'e.source_type', 'e.source_id',
                'acc.number as account_number', 'acc.name_ar as account_name_ar', 'acc.name_en as account_name_en',
            ])
            ->orderBy('e.date')
            ->orderBy('e.id')
            ->orderBy('l.sort')
            ->orderBy('l.id')
            ->get()
            ->map(function (object $line) {
                $line->debit = (float) $line->debit;
                $line->credit = (float) $line->credit;
                $line->account_name = static::localName($line->account_name_ar, $line->account_name_en);

                return $line;
            });
    }

    /**
     * Opening / period debit / period credit per value of $groupColumn (e.g. "l.account_id", "l.cost_center_id").
     *
     * @return array<int|string, array{opening: float, debit: float, credit: float, closing: float}>
     */
    public static function summary(Builder $query, string $groupColumn, ?string $from, string $to): array
    {
        $from = $from ? static::date($from) : null;
        $toNext = static::nextDay($to);
        $q = (clone $query)->where('e.date', '<', $toNext)->whereNotNull($groupColumn);

        if ($from) {
            $q->selectRaw("{$groupColumn} as group_key")
                ->selectRaw('COALESCE(SUM(CASE WHEN e.date < ? THEN l.debit - l.credit ELSE 0 END), 0) as opening', [$from])
                ->selectRaw('COALESCE(SUM(CASE WHEN e.date >= ? THEN l.debit ELSE 0 END), 0) as debit', [$from])
                ->selectRaw('COALESCE(SUM(CASE WHEN e.date >= ? THEN l.credit ELSE 0 END), 0) as credit', [$from]);
        } else {
            $q->selectRaw("{$groupColumn} as group_key")
                ->selectRaw('0 as opening')
                ->selectRaw('COALESCE(SUM(l.debit), 0) as debit')
                ->selectRaw('COALESCE(SUM(l.credit), 0) as credit');
        }

        $out = [];
        foreach ($q->groupBy($groupColumn)->get() as $row) {
            $opening = round((float) $row->opening, 2);
            $debit = round((float) $row->debit, 2);
            $credit = round((float) $row->credit, 2);
            $out[$row->group_key] = ['opening' => $opening, 'debit' => $debit, 'credit' => $credit, 'closing' => round($opening + $debit - $credit, 2)];
        }

        return $out;
    }

    /**
     * Every account keyed by id (small table; used to roll balances up the tree).
     *
     * @return Collection<int, Account>
     */
    public static function accounts(): Collection
    {
        return Account::query()->orderBy('number')->get()->keyBy('id');
    }

    /**
     * Ancestor of an account at the given level (or the account itself when it is at / above that level).
     *
     * @param  Collection<int, Account>  $accounts
     */
    public static function ancestorAtLevel(Collection $accounts, int $accountId, int $level): ?Account
    {
        $account = $accounts->get($accountId);
        while ($account && $account->level > $level && $account->parent_id) {
            $account = $accounts->get($account->parent_id);
        }

        return $account;
    }

    /**
     * Ids of an account and all its descendants.
     *
     * @param  Collection<int, Account>|null  $accounts
     * @return array<int, int>
     */
    public static function descendantIds(int $accountId, ?Collection $accounts = null): array
    {
        $accounts ??= static::accounts();
        $children = $accounts->groupBy('parent_id');
        $ids = [];
        $stack = [$accountId];
        while ($stack) {
            $id = array_pop($stack);
            $ids[] = $id;
            foreach ($children->get($id, collect()) as $child) {
                $stack[] = $child->id;
            }
        }

        return $ids;
    }

    /** Entry type labels (نوع القيد). @return array<string, string> */
    public static function entryTypes(): array
    {
        return [
            'manual' => ReportLang::get('Journal Voucher'),
            'opening' => ReportLang::get('Opening Balance'),
            'sales' => ReportLang::get('Sales Invoice'),
            'sales_return' => ReportLang::get('Sales Return'),
            'pos' => ReportLang::get('POS Sales'),
            'purchase' => ReportLang::get('Purchase Invoice'),
            'purchase_return' => ReportLang::get('Purchase Return'),
            'receipt' => ReportLang::get('Receipt Voucher'),
            'payment' => ReportLang::get('Payment Voucher'),
            'notice' => ReportLang::get('Notice'),
            'inventory' => ReportLang::get('Inventory'),
            'cheque_collection' => ReportLang::get('Cheque Collection'),
            'closing' => ReportLang::get('Closing Entry'),
            'credit_note' => ReportLang::get('Credit Note'),
            'debit_note' => ReportLang::get('Debit Note'),
            'financing' => ReportLang::get('Financing Contract'),
            'financing_installment' => ReportLang::get('Financing Installment'),
            'lc_fee' => ReportLang::get('Letter of Credit Fee'),
        ];
    }

    public static function entryTypeLabel(?string $type): string
    {
        return static::entryTypes()[$type] ?? ReportLang::get(str((string) $type)->headline()->toString());
    }

    public static function localName(?string $ar, ?string $en): string
    {
        return app()->getLocale() === 'en' && filled($en) ? (string) $en : (string) ($ar ?: $en);
    }

    /** Split a net amount into [debit, credit]. @return array{0: float, 1: float} */
    public static function split(float $net): array
    {
        $net = round($net, 2);

        return [$net > 0 ? $net : 0.0, $net < 0 ? -$net : 0.0];
    }
}
