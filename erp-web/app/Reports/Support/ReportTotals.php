<?php

namespace App\Reports\Support;

use App\Reports\ReportResult;

/**
 * Footer / subtotal helpers for list reports.
 */
class ReportTotals
{
    /**
     * Footer "Total" row summing the given columns over the regular rows.
     *
     * @param  array<int, string>  $keys
     * @param  array<string, mixed>  $extra  additional cells (e.g. a computed margin)
     */
    public static function footer(ReportResult $result, array $keys, string $labelKey, array $extra = []): ReportResult
    {
        $cells = [$labelKey => ReportLang::get('Total')];
        foreach ($keys as $key) {
            $cells[$key] = $result->sum($key);
        }

        return $result->addTotal(array_merge($cells, $extra));
    }

    /**
     * Sum of the given keys over a list of cell arrays.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $keys
     * @return array<string, float>
     */
    public static function sum(iterable $rows, array $keys): array
    {
        $out = array_fill_keys($keys, 0.0);
        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $out[$key] += (float) ($row[$key] ?? 0);
            }
        }

        return array_map(fn (float $v) => round($v, 3), $out);
    }
}
