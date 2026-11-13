<?php

namespace App\Printing;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One printable document type (`/print/{type}/{id}`).
 *
 * build() returns the data of the generic `print.document` view:
 *   title, subtitle, status, fields [[label, value]], partyTitle, partyFields [[label, value]],
 *   columns [key => ['label' => ..., 'align' => 'start'|'end']], rows [[key => value]], footerRow [key => value],
 *   totals [[label, value, bold]], amountInWords, notes, qr, signatures [label].
 */
abstract class PrintTemplate
{
    abstract public function find(int $id): Model;

    /**
     * @return array<string, mixed>
     */
    abstract public function build(Model $record, Request $request): array;

    public function view(Model $record, Request $request): string
    {
        return 'print.document';
    }

    /** @return array<int, string> page keys whose ".view" permission allows printing */
    public function permissionKeys(Model $record): array
    {
        return PrintPermissions::keysFor($record);
    }

    public function branch(Model $record): ?Branch
    {
        $branch = $record->getAttribute('branch_id') ? $record->getRelationValue('branch') : null;

        return $branch instanceof Branch ? $branch : null;
    }

    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }

    public static function quantity(mixed $value): string
    {
        $formatted = number_format((float) $value, 3, '.', ',');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    public static function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return ($value instanceof Carbon ? $value : Carbon::parse($value))->format('Y-m-d');
    }

    public static function percent(mixed $value): string
    {
        return static::quantity($value).'%';
    }

    /**
     * Drop fields whose value is blank.
     *
     * @param  array<int, array{0: string, 1: mixed}>  $fields
     * @return array<int, array{0: string, 1: string}>
     */
    protected function fields(array $fields): array
    {
        return array_values(array_map(
            fn (array $field): array => [__($field[0]), (string) $field[1]],
            array_filter($fields, fn (array $field): bool => filled($field[1])),
        ));
    }

    protected function statusLabel(Model $record): string
    {
        return $record->getAttribute('is_posted') ? __('Posted Document') : __('Unposted Document');
    }

    protected function partyName(?Model $party): ?string
    {
        if (! $party) {
            return null;
        }

        $code = $party->getAttribute('code');
        $name = $party->getAttribute('name');

        return filled($code) ? "{$code} - {$name}" : (string) $name;
    }

    protected function itemName(?Model $item): string
    {
        return (string) ($item?->getAttribute('name') ?? '');
    }
}
