<?php

namespace App\Reports\CashFlows;

use App\Models\Account;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Services\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash Flow Statement (قائمة التدفقات النقدية), direct method: movements on cash and bank accounts
 * (account type cash / bank) attributed to their counter-accounts and grouped by category.
 *
 * For every posted entry touching a cash account, each non-cash line contributes (credit - debit) to the cash
 * flow — in a balanced entry these add up to the cash movement. Transfers between cash accounts net to zero.
 * Footer: opening cash balance, net cash flow, closing cash balance.
 */
class CashFlowsListReport extends Report
{
    protected static string $group = 'cash-flows';

    protected static string $slug = 'cash-flows-list';

    protected static string $title = 'Cash Flow Statement';

    /** category => English label, in display order */
    public const CATEGORIES = [
        'customers' => 'Collections from Customers',
        'revenue' => 'Cash Sales and Other Revenue',
        'suppliers' => 'Payments to Suppliers',
        'inventory' => 'Purchases and Inventory',
        'expenses' => 'Expenses',
        'vat' => 'Value Added Tax',
        'investing' => 'Investing Activities',
        'financing' => 'Financing Activities',
    ];

    public function filters(): array
    {
        return [
            ReportFields::account('account_id', 'Cash / Bank Account', postableOnly: true, scope: fn ($q) => $q->whereIn('type', ['cash', 'bank'])),
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
        $branchId = $filters['branch_id'] ?? null;
        $accounts = Ledger::accounts();
        $cashIds = filled($filters['account_id'] ?? null)
            ? [(int) $filters['account_id']]
            : $accounts->filter(fn (Account $a) => $a->is_postable && in_array($a->type, ['cash', 'bank'], true))->keys()->all();

        $cashQuery = Ledger::forAccounts($cashIds, $branchId);
        $opening = Ledger::openingBalance($cashQuery, $filters['from']);

        $entryIds = Ledger::between(clone $cashQuery, $filters['from'], $filters['to'])->distinct()->pluck('e.id')->all();
        $lines = DB::table('journal_entry_lines')->whereIn('journal_entry_id', $entryIds ?: [0])->get(['account_id', 'debit', 'credit']);

        /** @var array<string, array<int, float>> $flows category => account id => net cash effect */
        $flows = [];
        foreach ($lines as $line) {
            if (in_array((int) $line->account_id, $cashIds, true)) {
                continue;
            }
            $category = $this->category($accounts, (int) $line->account_id);
            $flows[$category][(int) $line->account_id] = ($flows[$category][(int) $line->account_id] ?? 0.0) + (float) $line->credit - (float) $line->debit;
        }

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->columns([
                ReportColumn::text('number', 'No.'),
                ReportColumn::text('name', 'Description'),
                ReportColumn::money('inflow', 'Cash In'),
                ReportColumn::money('outflow', 'Cash Out'),
                ReportColumn::money('net', 'Net'),
            ]);
        if (count($cashIds) === 1) {
            $result->info('Account', ($a = $accounts->get($cashIds[0])) ? $a->number.' - '.$a->name : null);
        }

        $netFlow = 0.0;
        foreach (self::CATEGORIES as $category => $label) {
            $items = collect($flows[$category] ?? [])->map(fn ($v) => round($v, 2))->filter(fn ($v) => $v != 0.0);
            if ($items->isEmpty()) {
                continue;
            }
            $result->addGroup(static::t($label));
            $in = $out = 0.0;
            foreach ($items->sortKeys() as $accountId => $value) {
                $account = $accounts->get($accountId);
                $in += max($value, 0);
                $out += max(-$value, 0);
                $result->addRow([
                    'number' => $account?->number,
                    'name' => $account?->name,
                    'inflow' => $value > 0 ? $value : 0.0,
                    'outflow' => $value < 0 ? -$value : 0.0,
                    'net' => $value,
                ]);
            }
            $net = round($in - $out, 2);
            $netFlow += $net;
            $result->addSubtotal(['name' => static::t('Total :name', ['name' => static::t($label)]), 'inflow' => round($in, 2), 'outflow' => round($out, 2), 'net' => $net]);
        }

        $netFlow = round($netFlow, 2);
        $result->addTotal(['name' => static::t('Opening Cash Balance'), 'net' => $opening]);
        $result->addTotal(['name' => static::t('Net Cash Flow'), 'inflow' => $result->sum('inflow'), 'outflow' => $result->sum('outflow'), 'net' => $netFlow]);
        $result->addTotal(['name' => static::t('Closing Cash Balance'), 'net' => round($opening + $netFlow, 2)]);

        return $result;
    }

    /** @param  Collection<int, Account>  $accounts */
    protected function category(Collection $accounts, int $accountId): string
    {
        $account = $accounts->get($accountId);
        if (! $account) {
            return 'investing';
        }
        $cogsRoot = ($cogs = Settings::account('cogs')) ? Ledger::ancestorAtLevel($accounts, $cogs, 2)?->id : null;

        return match (true) {
            $account->type === 'customer' => 'customers',
            $account->type === 'supplier' => 'suppliers',
            $account->type === 'vat' => 'vat',
            $account->type === 'inventory' || ($cogsRoot && Ledger::ancestorAtLevel($accounts, $accountId, 2)?->id === $cogsRoot) => 'inventory',
            $account->balance_sheet_item === 'revenue' => 'revenue',
            $account->balance_sheet_item === 'expenses' => 'expenses',
            in_array($account->balance_sheet_item, ['equity', 'liabilities'], true) => 'financing',
            default => 'investing',
        };
    }
}
