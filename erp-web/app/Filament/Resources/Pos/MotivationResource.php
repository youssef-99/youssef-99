<?php

namespace App\Filament\Resources\Pos;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Pos\MotivationResource\Pages\ManageMotivations;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Motivation;
use App\Models\User;
use App\Services\MotivationService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Staff incentives (الحوافز) — /sales/motivations. Amount per unit or percent of sales, per item or category,
 * for the selling user (or every user); earned incentives are computed by MotivationService.
 */
class MotivationResource extends ErpResource
{
    protected static ?string $model = Motivation::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.motivations';

    protected static ?string $modelLabel = 'Staff Incentive';

    protected static ?string $pluralModelLabel = 'Staff Incentives';

    protected static ?string $slug = 'sales/motivations';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?int $navigationSort = 85;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Motivation::class, label: 'Incentive Code'),
            Select::make('motivation_type')->label('Incentive Type')->options(static::typeOptions())->default('per_unit')->required()->live(),
            ...Fields::names('Incentive Name'),
            TextInput::make('value')
                ->label(fn (Get $get) => $get('motivation_type') === 'percent' ? 'Incentive %' : 'Incentive Amount per Unit')
                ->numeric()->minValue(0)->required()
                ->maxValue(fn (Get $get) => $get('motivation_type') === 'percent' ? 100 : null),
            Select::make('user_id')->label('User')->relationship('user', 'name')
                ->getOptionLabelFromRecordUsing(fn (User $record) => $record->name ?: $record->username)
                ->searchable(['name', 'username'])->preload()->helperText(__('Leave empty for all users.')),
            Fields::relation('branch_id', 'branch', 'Branch')->helperText(__('Leave empty for all branches.')),
            Fields::relation('item_id', 'item', 'Item')->requiredWithout('category_id'),
            Fields::relation('category_id', 'category', 'Item Category')->requiredWithout('item_id'),
            DatePicker::make('from_date')->label('From Date')->required()->default(now()->startOfMonth())->native(false)->displayFormat('d/m/Y'),
            DatePicker::make('to_date')->label('To Date')->required()->default(now()->endOfMonth())->afterOrEqual('from_date')->native(false)->displayFormat('d/m/Y'),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Incentive Code'),
                Columns::name('Incentive Name'),
                TextColumn::make('motivation_type')->label('Incentive Type')->badge()->formatStateUsing(fn (?string $state) => static::typeOptions()[$state] ?? $state),
                TextColumn::make('value')->label('Value')->numeric(2),
                TextColumn::make('user.name')->label('User')->placeholder(__('All Users')),
                TextColumn::make('item.name_ar')->label('Item')->formatStateUsing(fn ($record) => $record->item?->label)->placeholder('-'),
                TextColumn::make('category.name_ar')->label('Item Category')->formatStateUsing(fn ($record) => $record->category?->name)->placeholder('-'),
                TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn ($record) => $record->branch?->name)->placeholder(__('All Branches'))->toggleable(),
                Columns::date('from_date', 'From Date'),
                Columns::date('to_date', 'To Date'),
                Columns::boolean(),
            ])
            ->filters([
                Filters::branch(),
                Filters::relation('item_id', 'item', 'Item'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('from_date', 'desc');
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return array_map(fn (string $label) => __($label), MotivationService::TYPES);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMotivations::route('/'),
        ];
    }
}
