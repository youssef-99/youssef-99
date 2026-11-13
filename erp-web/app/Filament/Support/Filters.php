<?php

namespace App\Filament\Support;

use App\Models\Branch;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * The common filter bar: Year (السنة), Branch (الفرع), Posted status, date range.
 */
class Filters
{
    public static function year(string $column = 'fiscal_year'): SelectFilter
    {
        $years = range((int) now()->format('Y') + 1, 2020);

        return SelectFilter::make($column)
            ->label('Year')
            ->options(array_combine($years, $years))
            ->default((int) now()->format('Y'));
    }

    public static function branch(string $column = 'branch_id', string $relationship = 'branch'): SelectFilter
    {
        return SelectFilter::make($column)
            ->label('Branch')
            ->relationship($relationship, 'name_ar')
            ->getOptionLabelFromRecordUsing(fn (Branch $record) => $record->name)
            ->preload();
    }

    public static function relation(string $column, string $relationship, string $label): SelectFilter
    {
        return SelectFilter::make($column)
            ->label($label)
            ->relationship($relationship, 'name_ar')
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->label ?? $record->name)
            ->searchable()
            ->preload();
    }

    public static function posted(): TernaryFilter
    {
        return TernaryFilter::make('is_posted')->label('Posted?');
    }

    public static function dateRange(string $column = 'date'): Filter
    {
        return Filter::make($column.'_range')
            ->label('Period')
            ->schema([
                DatePicker::make('from')->label('From Date')->native(false),
                DatePicker::make('to')->label('To Date')->native(false),
            ])
            ->columns(2)
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate($column, '>=', $d))
                ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate($column, '<=', $d)));
    }
}
