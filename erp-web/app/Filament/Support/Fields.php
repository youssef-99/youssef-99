<?php

namespace App\Filament\Support;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Services\DocumentNumber;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable form fields. Labels are English and translated automatically (see AppServiceProvider).
 */
class Fields
{
    /** Code field with unique validation; defaults to the next numeric code. */
    public static function code(string $modelClass, string $name = 'code', string $label = 'Code'): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->maxLength(50)
            ->default(fn () => DocumentNumber::nextCode($modelClass, $name))
            ->unique($modelClass, $name, ignoreRecord: true);
    }

    /** name_ar (required) + name_en. */
    public static function names(string $label = 'Name'): array
    {
        return [
            TextInput::make('name_ar')->label(__($label).' ('.__('Arabic').')')->required()->maxLength(255),
            TextInput::make('name_en')->label(__($label).' ('.__('English').')')->maxLength(255),
        ];
    }

    /**
     * BelongsTo select showing "code - name", searchable by code/name.
     *
     * @param  string[]  $search  columns to search
     */
    public static function relation(string $name, string $relationship, ?string $label = null, array $search = ['code', 'name_ar', 'name_en'], ?\Closure $modifyQuery = null, string $titleColumn = 'name_ar'): Select
    {
        return Select::make($name)
            ->label($label ?? str($relationship)->snake(' ')->headline()->toString())
            ->relationship($relationship, $titleColumn, $modifyQuery)
            ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->label ?? $record->name ?? $record->getKey())
            ->searchable($search)
            ->preload();
    }

    public static function branch(string $name = 'branch_id', string $relationship = 'branch', string $label = 'Branch'): Select
    {
        return static::relation($name, $relationship, $label)
            ->default(fn () => auth()->user()?->default_branch_id ?? Branch::query()->value('id'))
            ->required()
            ->live();
    }

    /** Warehouse filtered by the selected branch field. */
    public static function warehouse(string $name = 'warehouse_id', string $relationship = 'warehouse', string $branchField = 'branch_id', string $label = 'Warehouse'): Select
    {
        return Select::make($name)
            ->label($label)
            ->relationship($relationship, 'name_ar', fn (Builder $query, Get $get) => $query->when($get($branchField), fn ($q, $b) => $q->where('branch_id', $b)))
            ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->label)
            ->default(fn () => Warehouse::query()->where('branch_id', auth()->user()?->default_branch_id ?? Branch::query()->value('id'))->value('id'))
            ->searchable(['code', 'name_ar', 'name_en'])
            ->preload()
            ->required();
    }

    /** Account select (postable leaf accounts by default). */
    public static function account(string $name = 'account_id', string $relationship = 'account', string $label = 'Account', bool $postableOnly = true, ?\Closure $modifyQuery = null): Select
    {
        return Select::make($name)
            ->label($label)
            ->relationship($relationship, 'name_ar', function (Builder $query) use ($postableOnly, $modifyQuery) {
                $query->when($postableOnly, fn ($q) => $q->where('is_postable', true))->where('is_active', true)->orderBy('number');
                if ($modifyQuery) {
                    $modifyQuery($query);
                }
            })
            ->getOptionLabelFromRecordUsing(fn (Account $record) => $record->number.' - '.$record->name)
            ->searchable(['number', 'name_ar', 'name_en']);
    }

    /** Account select without a relationship (for repeater rows that aren't Eloquent relations). */
    public static function accountOptions(bool $postableOnly = true): array
    {
        return Account::query()
            ->when($postableOnly, fn ($q) => $q->where('is_postable', true))
            ->where('is_active', true)
            ->orderBy('number')
            ->get()
            ->mapWithKeys(fn (Account $a) => [$a->id => $a->number.' - '.$a->name])
            ->all();
    }

    public static function money(string $name, string $label = null): TextInput
    {
        return TextInput::make($name)
            ->label($label ?? str($name)->headline()->toString())
            ->numeric()
            ->default(0)
            ->step(0.01);
    }

    public static function percent(string $name, string $label = null): TextInput
    {
        return TextInput::make($name)
            ->label($label ?? str($name)->headline()->toString())
            ->numeric()
            ->default(0)
            ->minValue(0)
            ->maxValue(100)
            ->suffix('%');
    }

    public static function date(string $name = 'date', string $label = 'Date'): DatePicker
    {
        return DatePicker::make($name)->label($label)->default(now())->required()->native(false)->displayFormat('d/m/Y');
    }

    /** Document number: blank means "auto". */
    public static function number(string $name = 'number', string $label = 'No.'): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->placeholder(__('Auto'))
            ->maxLength(50);
    }

    public static function active(string $name = 'is_active', string $label = 'Active?'): Toggle
    {
        return Toggle::make($name)->label($label)->default(true);
    }
}
