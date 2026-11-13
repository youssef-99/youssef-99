<?php

namespace App\Reports\Accounts;

use App\Models\Account;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Journal Transactions (حركة القيود).
 * Filters: Transaction Type* (نوع الحركة: posted / unposted / all), Branch, Journal Entry Type (نوع قيد اليومية),
 * Account, Report Type* (summary = one row per entry, detail = every line), From*, To*.
 */
class AccountsJournalTransactionReport extends Report
{
    protected static string $group = 'accounts';

    protected static string $slug = 'accounts-journal-transaction';

    protected static string $title = 'Journal Transactions';

    public function filters(): array
    {
        return [
            ReportFields::select('transaction_type', 'Transaction Type', [
                'posted' => static::t('Posted'),
                'unposted' => static::t('Unposted'),
                'all' => static::t('All'),
            ])->required(),
            ReportFields::branch(),
            ReportFields::select('entry_type', 'Journal Entry Type', Ledger::entryTypes()),
            ReportFields::account(),
            ReportFields::reportType()->required(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['transaction_type' => 'posted', 'report_type' => 'detail', 'from' => ReportFields::fiscalYearStart(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $query = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('accounts as acc', 'acc.id', '=', 'l.account_id')
            ->when(($filters['transaction_type'] ?? 'posted') !== 'all', fn (Builder $q) => $q->where('e.is_posted', $filters['transaction_type'] === 'posted'))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $b) => $q->where('e.branch_id', $b))
            ->when($filters['entry_type'] ?? null, fn (Builder $q, $t) => $q->where('e.entry_type', $t));

        if ($accountId = $filters['account_id'] ?? null) {
            $ids = Ledger::descendantIds((int) $accountId);
            $query->whereIn('e.id', DB::table('journal_entry_lines')->whereIn('account_id', $ids)->select('journal_entry_id'));
        }
        Ledger::between($query, $filters['from'], $filters['to']);

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Journal Entry Type', filled($filters['entry_type'] ?? null) ? Ledger::entryTypeLabel($filters['entry_type']) : null)
            ->info('Account', ($a = Account::query()->find($filters['account_id'] ?? 0)) ? $a->number.' - '.$a->name : null);

        $filters['report_type'] === 'summary' ? $this->summary($result, $query) : $this->detail($result, $query);

        return $result;
    }

    protected function summary(ReportResult $result, Builder $query): void
    {
        $result->columns([
            ReportColumn::date('date', 'Date'),
            ReportColumn::text('entry', 'Entry'),
            ReportColumn::text('number', 'No.'),
            ReportColumn::text('description', 'Description'),
            ReportColumn::text('status', 'Status'),
            ReportColumn::money('debit', 'Debit'),
            ReportColumn::money('credit', 'Credit'),
        ]);

        $entries = $query->groupBy('e.id', 'e.date', 'e.entry_type', 'e.number', 'e.description', 'e.is_posted')
            ->select('e.id', 'e.date', 'e.entry_type', 'e.number', 'e.description', 'e.is_posted')
            ->selectRaw('SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->orderBy('e.date')->orderBy('e.id')
            ->get();

        foreach ($entries as $e) {
            $result->addRow([
                'date' => $e->date,
                'entry' => Ledger::entryTypeLabel($e->entry_type),
                'number' => $e->number,
                'description' => $e->description,
                'status' => $e->is_posted ? static::t('Posted') : static::t('Unposted'),
                'debit' => round((float) $e->debit, 2),
                'credit' => round((float) $e->credit, 2),
            ]);
        }

        $result->addTotal(['description' => static::t('Total').' ('.$entries->count().')', 'debit' => $result->sum('debit'), 'credit' => $result->sum('credit')]);
    }

    protected function detail(ReportResult $result, Builder $query): void
    {
        $result->landscape()->columns([
            ReportColumn::date('date', 'Date'),
            ReportColumn::text('entry', 'Entry'),
            ReportColumn::text('number', 'No.'),
            ReportColumn::text('account_number', 'Account No.'),
            ReportColumn::text('account', 'Account Name'),
            ReportColumn::text('description', 'Description'),
            ReportColumn::money('debit', 'Debit'),
            ReportColumn::money('credit', 'Credit'),
        ]);

        $lines = $query->select('l.*', 'e.date', 'e.entry_type', 'e.number as entry_number', 'e.description as entry_description', 'acc.number as account_number', 'acc.name_ar', 'acc.name_en')
            ->orderBy('e.date')->orderBy('e.id')->orderBy('l.sort')->orderBy('l.id')
            ->get();

        $currentEntry = null;
        $entryDebit = $entryCredit = 0.0;
        foreach ($lines as $line) {
            if ($currentEntry !== null && $currentEntry !== $line->journal_entry_id) {
                $result->addSubtotal(['description' => static::t('Entry Total'), 'debit' => round($entryDebit, 2), 'credit' => round($entryCredit, 2)]);
                $entryDebit = $entryCredit = 0.0;
            }
            $currentEntry = $line->journal_entry_id;
            $entryDebit += (float) $line->debit;
            $entryCredit += (float) $line->credit;
            $result->addRow([
                'date' => $line->date,
                'entry' => Ledger::entryTypeLabel($line->entry_type),
                'number' => $line->entry_number,
                'account_number' => $line->account_number,
                'account' => Ledger::localName($line->name_ar, $line->name_en),
                'description' => $line->description ?: $line->entry_description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
            ]);
        }
        if ($currentEntry !== null) {
            $result->addSubtotal(['description' => static::t('Entry Total'), 'debit' => round($entryDebit, 2), 'credit' => round($entryCredit, 2)]);
        }

        $result->addTotal(['description' => static::t('Total'), 'debit' => $result->sum('debit'), 'credit' => $result->sum('credit')]);
    }
}
