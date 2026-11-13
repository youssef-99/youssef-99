<?php

namespace App\Filament\Resources\Pos;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Pos\PromotionResource\Pages\ManagePromotions;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Promotion;
use App\Services\PromotionService;
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
 * Promotions (العروض) — /sales/promotions. Applied automatically to POS lines (PromotionService).
 */
class PromotionResource extends ErpResource
{
    protected static ?string $model = Promotion::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.promotions';

    protected static ?string $modelLabel = 'Promotion';

    protected static ?string $pluralModelLabel = 'Promotions';

    protected static ?string $slug = 'sales/promotions';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static ?int $navigationSort = 84;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Promotion::class, label: 'Promotion Code'),
            Select::make('promotion_type')->label('Promotion Type')->options(static::typeOptions())->default('percent')->required()->live(),
            ...Fields::names('Promotion Name'),
            TextInput::make('value')
                ->label(fn (Get $get) => $get('promotion_type') === 'fixed_price' ? 'Promotion Price' : 'Discount %')
                ->numeric()->minValue(0)->default(0)
                ->maxValue(fn (Get $get) => $get('promotion_type') === 'percent' ? 100 : null)
                ->required(fn (Get $get) => $get('promotion_type') !== 'buy_x_get_y')
                ->visible(fn (Get $get) => $get('promotion_type') !== 'buy_x_get_y'),
            TextInput::make('buy_qty')->label('Buy Quantity')->numeric()->minValue(0.001)->required()
                ->visible(fn (Get $get) => $get('promotion_type') === 'buy_x_get_y'),
            TextInput::make('free_qty')->label('Free Quantity')->numeric()->minValue(0.001)->required()
                ->visible(fn (Get $get) => $get('promotion_type') === 'buy_x_get_y'),
            Fields::relation('branch_id', 'branch', 'Branch')->helperText(__('Leave empty for all branches.')),
            Fields::relation('item_id', 'item', 'Item')->requiredWithout('category_id'),
            Fields::relation('category_id', 'category', 'Item Category')->requiredWithout('item_id'),
            DatePicker::make('from_date')->label('From Date')->required()->default(now())->native(false)->displayFormat('d/m/Y'),
            DatePicker::make('to_date')->label('To Date')->required()->default(now()->addMonth())->afterOrEqual('from_date')->native(false)->displayFormat('d/m/Y'),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Promotion Code'),
                Columns::name('Promotion Name'),
                TextColumn::make('promotion_type')->label('Promotion Type')->badge()->formatStateUsing(fn (?string $state) => static::typeOptions()[$state] ?? $state),
                TextColumn::make('value')->label('Value')->numeric(2)
                    ->formatStateUsing(fn ($record) => $record->promotion_type === 'buy_x_get_y' ? (float) $record->buy_qty.' + '.(float) $record->free_qty : number_format((float) $record->value, 2)),
                TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn ($record) => $record->branch?->name)->placeholder(__('All Branches')),
                TextColumn::make('item.name_ar')->label('Item')->formatStateUsing(fn ($record) => $record->item?->label)->placeholder('-'),
                TextColumn::make('category.name_ar')->label('Item Category')->formatStateUsing(fn ($record) => $record->category?->name)->placeholder('-'),
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
        return array_map(fn (string $label) => __($label), PromotionService::TYPES);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePromotions::route('/'),
        ];
    }
}
