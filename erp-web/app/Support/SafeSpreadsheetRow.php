<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;

/**
 * Builds export rows that cannot carry spreadsheet formulas (CSV/formula injection).
 * OpenSpout turns any string starting with "=" into a formula cell, and spreadsheet apps also
 * evaluate "+", "-", "@", tab and carriage return prefixes in CSV files; such text gets a leading quote.
 */
class SafeSpreadsheetRow
{
    /**
     * @param  array<int, bool|\DateInterval|\DateTimeInterface|float|int|string|null>  $values
     */
    public static function fromValues(array $values): Row
    {
        return Row::fromValues(array_map(static::sanitize(...), $values));
    }

    public static function sanitize(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && ! is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
