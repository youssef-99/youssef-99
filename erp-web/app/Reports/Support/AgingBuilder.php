<?php

namespace App\Reports\Support;

use App\Reports\ReportColumn;
use Illuminate\Support\Carbon;

/**
 * Debt ageing (أعمار الديون) by FIFO: the outstanding balance is assumed to come from the most recent
 * charges (invoices), so it is allocated to charges newest-first and each part is aged by
 * (as-of date - charge date). Whatever is not covered by a charge in the period goes to the oldest bucket.
 * Buckets with a period of N days: 0-N, N+1-2N, 2N+1-3N, 3N+1-4N, over 4N.
 */
class AgingBuilder
{
    public const BUCKETS = 5;

    /** @return array<int, string> bucket labels */
    public static function labels(int $days): array
    {
        $labels = [];
        for ($i = 0; $i < static::BUCKETS - 1; $i++) {
            $labels[] = ($i === 0 ? 0 : $i * $days + 1).' - '.(($i + 1) * $days);
        }
        $labels[] = ReportLang::get('Over :days', ['days' => (static::BUCKETS - 1) * $days]);

        return $labels;
    }

    /** @return array<int, ReportColumn> */
    public static function columns(int $days): array
    {
        $columns = [
            ReportColumn::text('code', 'Code'),
            ReportColumn::text('name', 'Name'),
            ReportColumn::money('balance', 'Balance'),
        ];
        foreach (static::labels($days) as $i => $label) {
            $columns[] = new ReportColumn("bucket_{$i}", $label, 'money', ReportLang::get('Age (days)'));
        }

        return $columns;
    }

    /**
     * @param  float  $balance  outstanding amount (positive = owed by the party / to the supplier)
     * @param  iterable<array{date: string, amount: float}>  $charges  charges in the period (any order)
     * @return array<string, float> bucket_0..bucket_4
     */
    public static function allocate(float $balance, iterable $charges, string $asOf, int $days): array
    {
        $buckets = array_fill_keys(array_map(fn ($i) => "bucket_{$i}", range(0, static::BUCKETS - 1)), 0.0);
        $days = max(1, $days);

        if ($balance <= 0) {
            $buckets['bucket_0'] = round($balance, 2);

            return $buckets;
        }

        $list = is_array($charges) ? $charges : iterator_to_array($charges, false);
        usort($list, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        $asOfDate = Carbon::parse($asOf)->startOfDay();
        $remaining = round($balance, 2);

        foreach ($list as $charge) {
            if ($remaining <= 0) {
                break;
            }
            $part = min($remaining, (float) $charge['amount']);
            if ($part <= 0) {
                continue;
            }
            $age = max(0, (int) Carbon::parse($charge['date'])->startOfDay()->diffInDays($asOfDate, false));
            $index = min(static::BUCKETS - 1, intdiv(max(0, $age - 1), $days));
            $buckets["bucket_{$index}"] = round($buckets["bucket_{$index}"] + $part, 2);
            $remaining = round($remaining - $part, 2);
        }

        if ($remaining > 0) {
            $last = 'bucket_'.(static::BUCKETS - 1);
            $buckets[$last] = round($buckets[$last] + $remaining, 2);
        }

        return $buckets;
    }
}
