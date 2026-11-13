<?php

namespace App\Services;

use App\Exceptions\ErpException;
use App\Models\Account;
use App\Models\JournalEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates and posts journal entries. Every posting document goes through here.
 */
class JournalService
{
    /**
     * Build (or rebuild) the journal entry for a source document.
     *
     * @param  array<int, array{account_id:int, debit?:float, credit?:float, description?:string, cost_center_id?:int, salesman_id?:int, accreditation_id?:int, document_no?:string, document_date?:string}>  $lines
     */
    public static function record(
        Model $source,
        string $entryType,
        int $branchId,
        CarbonInterface|string $date,
        array $lines,
        ?string $description = null,
        bool $post = true,
    ): JournalEntry {
        $date = Carbon::parse($date);
        $lines = static::normalize($lines);
        static::assertBalanced($lines);

        return DB::transaction(function () use ($source, $entryType, $branchId, $date, $lines, $description, $post) {
            $entry = JournalEntry::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->first();

            $year = (int) $date->format('Y');

            if (! $entry) {
                $entry = new JournalEntry([
                    'branch_id' => $branchId,
                    'fiscal_year' => $year,
                    'entry_type' => $entryType,
                    'number' => DocumentNumber::next("journal.{$entryType}", $branchId, $year),
                    'created_by' => auth()->id(),
                ]);
                $entry->source()->associate($source);
            }

            $entry->fill([
                'date' => $date,
                'description' => $description,
                'total_debit' => round(array_sum(array_column($lines, 'debit')), 2),
                'total_credit' => round(array_sum(array_column($lines, 'credit')), 2),
                'is_posted' => $post,
                'posted_at' => $post ? now() : null,
            ])->save();

            $entry->lines()->delete();
            foreach (array_values($lines) as $i => $line) {
                $entry->lines()->create($line + ['sort' => $i]);
            }

            return $entry;
        });
    }

    /**
     * Always create a new posted entry for a source (for sources that own several entries,
     * e.g. a financing contract and its installment payments). record() keeps one entry per source.
     *
     * @param  array<int, array{account_id:int, debit?:float, credit?:float, description?:string, cost_center_id?:int, salesman_id?:int, accreditation_id?:int, document_no?:string, document_date?:string}>  $lines
     */
    public static function append(
        Model $source,
        string $entryType,
        int $branchId,
        CarbonInterface|string $date,
        array $lines,
        ?string $description = null,
    ): JournalEntry {
        $date = Carbon::parse($date);
        $lines = static::normalize($lines);
        static::assertBalanced($lines);

        return DB::transaction(function () use ($source, $entryType, $branchId, $date, $lines, $description): JournalEntry {
            $year = (int) $date->format('Y');
            $entry = new JournalEntry([
                'branch_id' => $branchId,
                'fiscal_year' => $year,
                'entry_type' => $entryType,
                'number' => DocumentNumber::next("journal.{$entryType}", $branchId, $year),
                'created_by' => auth()->id(),
                'date' => $date,
                'description' => $description,
                'total_debit' => round(array_sum(array_column($lines, 'debit')), 2),
                'total_credit' => round(array_sum(array_column($lines, 'credit')), 2),
                'is_posted' => true,
                'posted_at' => now(),
            ]);
            $entry->source()->associate($source);
            $entry->save();

            foreach (array_values($lines) as $i => $line) {
                $entry->lines()->create($line + ['sort' => $i]);
            }

            return $entry;
        });
    }

    /** Delete the entry generated for a document (used when un-posting). */
    public static function removeFor(Model $source): void
    {
        JournalEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->get()
            ->each(fn (JournalEntry $e) => $e->delete());
    }

    /** Merge same-account lines, drop zero lines, net debit/credit on the same account+dimension. */
    public static function normalize(array $lines): array
    {
        $grouped = [];
        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            if (empty($line['account_id'])) {
                if ($debit == 0 && $credit == 0) {
                    continue;
                }
                throw new ErpException(__('A journal line is missing its account.'));
            }
            $key = implode('|', [$line['account_id'], $line['cost_center_id'] ?? '', $line['salesman_id'] ?? '', $line['accreditation_id'] ?? '', $line['description'] ?? '']);
            $grouped[$key] ??= array_merge($line, ['debit' => 0, 'credit' => 0]);
            $grouped[$key]['debit'] = round(($grouped[$key]['debit'] ?? 0) + $debit, 2);
            $grouped[$key]['credit'] = round(($grouped[$key]['credit'] ?? 0) + $credit, 2);
        }

        $out = [];
        foreach ($grouped as $line) {
            $netAmount = round($line['debit'] - $line['credit'], 2);
            if ($netAmount == 0.0) {
                continue;
            }
            $line['debit'] = $netAmount > 0 ? $netAmount : 0;
            $line['credit'] = $netAmount < 0 ? -$netAmount : 0;
            $out[] = $line;
        }

        return $out;
    }

    public static function assertBalanced(array $lines): void
    {
        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        if (abs($debit - $credit) > 0.009) {
            throw new ErpException(__('The entry is not balanced: debit :debit, credit :credit.', ['debit' => number_format($debit, 2), 'credit' => number_format($credit, 2)]));
        }

        if (! $lines) {
            throw new ErpException(__('The entry has no lines.'));
        }

        $ids = array_unique(array_column($lines, 'account_id'));
        $bad = Account::query()->whereIn('id', $ids)->where('is_postable', false)->pluck('number');
        if ($bad->isNotEmpty()) {
            throw new ErpException(__('Accounts :accounts are parent accounts and cannot be posted to.', ['accounts' => $bad->implode(', ')]));
        }
    }

    /**
     * Account balance (debit - credit) for a date range. Opening balance = everything before $from.
     *
     * @return array{opening: float, debit: float, credit: float, closing: float}
     */
    public static function balance(int|array $accountIds, ?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        $ids = (array) $accountIds;
        $base = fn () => DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $ids)
            ->where('e.is_posted', true)
            ->when($branchId, fn ($q) => $q->where('e.branch_id', $branchId));

        // Compare on day boundaries: date columns may hold "Y-m-d 00:00:00" (SQLite), so "<= Y-m-d" would drop the last day.
        $fromDay = $from ? Carbon::parse($from)->toDateString() : null;
        $toNextDay = $to ? Carbon::parse($to)->addDay()->toDateString() : null;

        $opening = $fromDay ? (float) $base()->where('e.date', '<', $fromDay)->sum(DB::raw('l.debit - l.credit')) : 0.0;
        $period = $base()
            ->when($fromDay, fn ($q) => $q->where('e.date', '>=', $fromDay))
            ->when($toNextDay, fn ($q) => $q->where('e.date', '<', $toNextDay))
            ->selectRaw('COALESCE(SUM(l.debit),0) as d, COALESCE(SUM(l.credit),0) as c')
            ->first();

        $debit = (float) $period->d;
        $credit = (float) $period->c;

        return [
            'opening' => round($opening, 2),
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'closing' => round($opening + $debit - $credit, 2),
        ];
    }
}
