<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable table columns.
 */
class Columns
{
    public static function code(string $name = 'code', string $label = 'Code'): TextColumn
    {
        return TextColumn::make($name)->label($label)->searchable()->sortable();
    }

    /** Shows the name in the current locale; searches both names. */
    public static function name(string $label = 'Name', string $prefix = ''): TextColumn
    {
        $column = $prefix ? "{$prefix}.name_ar" : 'name_ar';

        return TextColumn::make($column)
            ->label($label)
            ->formatStateUsing(function ($state, $record) use ($prefix) {
                $target = $prefix ? data_get($record, $prefix) : $record;

                return $target?->name ?? $state;
            })
            ->searchable(query: function (Builder $query, string $search) use ($prefix) {
                $apply = fn (Builder $q) => $q->where(fn ($w) => $w->where('name_ar', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%"));

                return $prefix ? $query->whereHas($prefix, $apply) : $apply($query);
            })
            ->sortable();
    }

    public static function money(string $name, ?string $label = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label ?? str($name)->headline()->toString())
            ->numeric(2)
            ->alignEnd()
            ->sortable();
    }

    public static function date(string $name = 'date', string $label = 'Date'): TextColumn
    {
        return TextColumn::make($name)->label($label)->date('d/m/Y')->sortable();
    }

    public static function boolean(string $name = 'is_active', string $label = 'Active?'): IconColumn
    {
        return IconColumn::make($name)->label($label)->boolean();
    }

    public static function posted(): IconColumn
    {
        return IconColumn::make('is_posted')->label('Posted?')->boolean();
    }
}
