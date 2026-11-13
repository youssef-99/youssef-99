<?php

namespace App\Reports;

use App\Reports\Support\ReportLang;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * One column of a report table. `type` drives formatting in the viewer and the cell type in exports:
 * text | money (2 decimals) | number (`decimals` places) | date (d/m/Y).
 * `group` is an optional header band shown above the column (e.g. "Opening Balance" over Debit / Credit).
 */
final class ReportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public ?string $group = null,
        public int $decimals = 2,
    ) {}

    public static function text(string $key, string $label, ?string $group = null): self
    {
        return new self($key, ReportLang::get($label), 'text', $group ? ReportLang::get($group) : null);
    }

    public static function money(string $key, string $label, ?string $group = null): self
    {
        return new self($key, ReportLang::get($label), 'money', $group ? ReportLang::get($group) : null);
    }

    public static function number(string $key, string $label, int $decimals = 0, ?string $group = null): self
    {
        return new self($key, ReportLang::get($label), 'number', $group ? ReportLang::get($group) : null, $decimals);
    }

    public static function date(string $key, string $label, ?string $group = null): self
    {
        return new self($key, ReportLang::get($label), 'date', $group ? ReportLang::get($group) : null);
    }

    public function isNumeric(): bool
    {
        return in_array($this->type, ['money', 'number'], true);
    }

    /** Value formatted for display in the viewer. */
    public function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($this->type) {
            'money' => is_numeric($value) ? number_format((float) $value, 2) : (string) $value,
            'number' => is_numeric($value) ? number_format((float) $value, $this->decimals) : (string) $value,
            'date' => self::formatDate($value),
            default => (string) $value,
        };
    }

    /** Value written to XLSX / CSV: numbers stay numeric, dates become d/m/Y strings. */
    public function exportValue(mixed $value): string|int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            'money' => is_numeric($value) ? round((float) $value, 2) : (string) $value,
            'number' => is_numeric($value) ? round((float) $value, $this->decimals) : (string) $value,
            'date' => self::formatDate($value),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    public static function formatDate(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('d/m/Y');
        }

        try {
            return Carbon::parse((string) $value)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
