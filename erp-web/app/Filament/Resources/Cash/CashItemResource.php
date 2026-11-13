<?php

namespace App\Filament\Resources\Cash;

use App\Enums\Module;
use App\Filament\Resources\Cash\CashItemResource\Pages\ManageCashItems;
use App\Filament\Resources\ErpResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Item;
use App\Models\Unit;
use App\Services\Settings;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Cash Transactions > Items (الأصناف) — /cash/items.
 * A simplified list / modal form over the inventory items (full item master lives in Inventory).
 */
class CashItemResource extends ErpResource
{
    protected static ?string $model = Item::class;

    protected static ?Module $module = Module::Cash;

    protected static ?string $permissionKey = 'cash.items';

    protected static ?string $modelLabel = 'Item';

    protected static ?string $pluralModelLabel = 'Items';

    protected static ?string $slug = 'cash/items';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 20;

    /** @return array<string, string> */
    public static function itemTypes(): array
    {
        return [
            'standard' => __('Standard'),
            'batch_expiry' => __('Expiry Date + Batch No.'),
            'serial' => __('Serial Number'),
            'service' => __('Service'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Item::class, label: 'Item Code'),
            TextInput::make('barcode')->label('Barcode')->maxLength(50),
            TextInput::make('gtin')->label('GTIN')->maxLength(14),
            ...Fields::names('Item Name'),
            Fields::relation('category_id', 'category', 'Item Category')->required(),
            Fields::relation('unit_id', 'unit', 'Unit')->default(fn () => Unit::query()->value('id')),
            Select::make('item_type')->label('Item Type')->options(static::itemTypes())->default('standard')->required(),
            Fields::percent('vat_rate', 'Tax %')->default(fn (): float => Settings::defaultVatRate()),
            Fields::money('purchase_price', 'Purchase Price')->minValue(0),
            Fields::money('sale_price', 'Selling Price')->minValue(0),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Item Code'),
                TextColumn::make('barcode')->label('Barcode')->searchable()->toggleable(),
                Columns::name('Item Name'),
                TextColumn::make('category.name_ar')->label('Item Category')->formatStateUsing(fn (Item $record) => $record->category?->name),
                TextColumn::make('vat_rate')->label('Tax %')->numeric(2),
                Columns::money('purchase_price', 'Purchase Price'),
                Columns::money('sale_price', 'Selling Price'),
                Columns::boolean(),
            ])
            ->filters([Filters::relation('category_id', 'category', 'Item Category')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCashItems::route('/'),
        ];
    }
}
