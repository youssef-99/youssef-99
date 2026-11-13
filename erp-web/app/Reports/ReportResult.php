<?php

namespace App\Reports;

use App\Reports\Support\ReportLang;

/**
 * Output of a report run: what the viewer renders and the exporters write.
 *
 * Rows are plain arrays: `['type' => 'row'|'opening'|'group'|'subtotal', 'cells' => [columnKey => value], 'label' => ?string]`.
 *  - row:      a regular data row
 *  - opening:  an opening-balance row (rendered italic)
 *  - group:    a group header spanning the whole table (uses `label`)
 *  - subtotal: a bold row inside the body (group totals)
 * Footer rows (`addTotal()`) are rendered bold in the table footer and repeated on every printed page.
 */
final class ReportResult
{
    /** @var array<int, ReportColumn> */
    public array $columns = [];

    /** @var array<int, array{type: string, cells: array<string, mixed>, label: ?string}> */
    public array $rows = [];

    /** @var array<int, array{type: string, cells: array<string, mixed>, label: ?string}> */
    public array $footer = [];

    /** @var array<string, string> info lines shown under the title, label => value */
    public array $info = [];

    /** @var array<int, string> free notes shown under the table */
    public array $notes = [];

    public bool $landscape = false;

    public function __construct(public string $title, public ?string $subtitle = null) {}

    public static function make(string $title, ?string $subtitle = null): self
    {
        return new self($title, $subtitle);
    }

    /** @param  array<int, ReportColumn>  $columns */
    public function columns(array $columns): self
    {
        $this->columns = array_values($columns);

        return $this;
    }

    public function info(string $label, ?string $value): self
    {
        if (filled($value)) {
            $this->info[ReportLang::get($label)] = (string) $value;
        }

        return $this;
    }

    /** "From dd/mm/yyyy To dd/mm/yyyy" line. */
    public function period(?string $from, ?string $to): self
    {
        $parts = [];
        if (filled($from)) {
            $parts[] = ReportLang::get('From').' '.ReportColumn::formatDate($from);
        }
        if (filled($to)) {
            $parts[] = ReportLang::get('To').' '.ReportColumn::formatDate($to);
        }

        return $parts ? $this->info('Period', implode(' ', $parts)) : $this;
    }

    public function landscape(bool $landscape = true): self
    {
        $this->landscape = $landscape;

        return $this;
    }

    /** @param  array<string, mixed>  $cells */
    public function addRow(array $cells, string $type = 'row'): self
    {
        $this->rows[] = ['type' => $type, 'cells' => $cells, 'label' => null];

        return $this;
    }

    public function addGroup(string $label): self
    {
        $this->rows[] = ['type' => 'group', 'cells' => [], 'label' => $label];

        return $this;
    }

    /** @param  array<string, mixed>  $cells */
    public function addSubtotal(array $cells): self
    {
        return $this->addRow($cells, 'subtotal');
    }

    /** @param  array<string, mixed>  $cells */
    public function addTotal(array $cells): self
    {
        $this->footer[] = ['type' => 'total', 'cells' => $cells, 'label' => null];

        return $this;
    }

    public function addNote(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    public function hasColumnGroups(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->group !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Header bands for a two-row header: [label, colspan, isGroup].
     *
     * @return array<int, array{label: string, span: int, grouped: bool}>
     */
    public function headerBands(): array
    {
        $bands = [];
        foreach ($this->columns as $column) {
            $last = $bands === [] ? null : $bands[array_key_last($bands)];
            if ($column->group !== null && $last !== null && $last['grouped'] && $last['label'] === $column->group) {
                $bands[array_key_last($bands)]['span']++;

                continue;
            }
            $bands[] = ['label' => $column->group ?? $column->label, 'span' => 1, 'grouped' => $column->group !== null];
        }

        return $bands;
    }

    /** Sum of a column over regular rows (and opening rows when asked). */
    public function sum(string $key, bool $includeOpening = false): float
    {
        $total = 0.0;
        foreach ($this->rows as $row) {
            if ($row['type'] === 'row' || ($includeOpening && $row['type'] === 'opening')) {
                $total += (float) ($row['cells'][$key] ?? 0);
            }
        }

        return round($total, 2);
    }

    /**
     * Data-only matrix for exports: header row + body + footer, raw values.
     *
     * @return array<int, array<int, string|int|float|null>>
     */
    public function toMatrix(): array
    {
        $header = array_map(fn (ReportColumn $c) => $c->group ? "{$c->group} - {$c->label}" : $c->label, $this->columns);
        $matrix = [$header];

        foreach ([...$this->rows, ...$this->footer] as $row) {
            if ($row['type'] === 'group') {
                $matrix[] = [$row['label']];

                continue;
            }
            $matrix[] = array_map(fn (ReportColumn $c) => $c->exportValue($row['cells'][$c->key] ?? null), $this->columns);
        }

        return $matrix;
    }
}
