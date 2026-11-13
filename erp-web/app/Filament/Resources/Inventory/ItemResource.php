<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\ItemNature;
use App\Enums\ItemType;
use App\Enums\Module;
use App\Enums\TaxExemptionReason;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\ItemResource\Pages\ManageItems;
use App\Filament\Resources\Inventory\Support\BalanceTable;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Item;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Items (الأصناف) — /inventory/items. Add / edit in a modal; "Add Items" imports a file (see ManageItems).
 */
class ItemResource extends ErpResource
{
    protected static ?string $model = Item::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.items';

    protected static ?string $modelLabel = 'Item';

    protected static ?string $pluralModelLabel = 'Items';

    protected static ?string $slug = 'inventory/items';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 3])->schema([
                Fields::relation('agent_id', 'agent', 'Agent'),
                Fields::relation('supplier_id', 'supplier', 'Supplier'),
                TextInput::make('barcode')->label('Barcode')->maxLength(50)
                    ->unique(Item::class, 'barcode', ignoreRecord: true),
                Fields::relation('category_id', 'category', 'Category')->required(),
                Fields::code(Item::class, label: 'Item Code'),
                TextInput::make('reference_no')->label('Reference No.')->required()->maxLength(50)
                    ->default(fn (Get $get) => $get('code')),
                ...Fields::names('Item Name'),
                TextInput::make('gtin')->label('GTIN')->maxLength(14)->regex('/^\d{8,14}$/'),
                Select::make('item_type')->label('Item Type')->options(ItemType::options())->default(ItemType::BatchExpiry->value)->required(),
                Select::make('item_nature')->label('Item Nature')->options(ItemNature::options())->default(ItemNature::Stock->value)->required(),
                Fields::relation('unit_id', 'unit', 'Unit'),
            ]),
            Section::make(__('Tax and Prices'))->columns(['default' => 1, 'md' => 3])->schema([
                Fields::percent('vat_rate', 'VAT %')->default(config('erp.default_vat_rate'))->required()->live(onBlur: true),
                Select::make('tax_exemption_reason')->label('Tax Exemption Reason')
                    ->options(TaxExemptionReason::options())
                    ->searchable()
                    ->default(TaxExemptionReason::Medicines->value)
                    ->visible(fn (Get $get) => is_numeric($get('vat_rate')) && (float) $get('vat_rate') == 0.0)
                    ->required(fn (Get $get) => is_numeric($get('vat_rate')) && (float) $get('vat_rate') == 0.0),
                Fields::money('purchase_price', 'Purchase Price')->minValue(0),
                Fields::money('sale_price', 'Selling Price')->minValue(0),
                Toggle::make('price_includes_tax')->label('Price Includes Tax?')->default(false),
            ]),
            Section::make(__('Pharmacy'))->columns(['default' => 1, 'md' => 3])->schema([
                Fields::relation('manufacturer_id', 'manufacturer', 'Manufacturer'),
                Fields::relation('group_id', 'group', 'Group'),
                Select::make('activeIngredients')->label('Active Ingredients')
                    ->relationship('activeIngredients', 'name_ar')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->label)
                    ->multiple()
                    ->searchable(['code', 'name_ar', 'name_en'])
                    ->preload(),
                Fields::active(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->columns([
                Columns::code(label: 'Item Code'),
                TextColumn::make('barcode')->label('Barcode')->searchable(),
                TextColumn::make('gtin')->label('GTIN')->searchable()->toggleable(),
                Columns::name('Item Name'),
                TextColumn::make('category.name_ar')->label('Category')->formatStateUsing(fn ($record) => $record->category?->name),
                TextColumn::make('item_type')->label('Item Type')->formatStateUsing(fn (?string $state) => ItemType::tryFrom((string) $state)?->label() ?? $state)->toggleable(),
                TextColumn::make('item_nature')->label('Item Nature')->formatStateUsing(fn (?string $state) => ItemNature::tryFrom((string) $state)?->label() ?? $state)->toggleable(),
                TextColumn::make('vat_rate')->label('Tax %')->numeric(2)->suffix('%'),
                Columns::money('sale_price', 'Selling Price')->toggleable(),
                Columns::money('purchase_price', 'Purchase Price')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('balance')->label('Balance')
                    ->state(fn () => __('View'))
                    ->color('primary')
                    ->icon('heroicon-o-eye')
                    ->action(static::balanceAction()),
                Columns::boolean(),
            ])
            ->filters([
                Filters::relation('agent_id', 'agent', 'Agent'),
                Filters::relation('supplier_id', 'supplier', 'Supplier'),
                Filter::make('barcode')
                    ->label('Barcode')
                    ->schema([TextInput::make('barcode')->label('Barcode')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['barcode'] ?? null, fn ($q, $code) => $q->where(fn ($w) => $w->where('barcode', $code)->orWhere('gtin', $code))))
                    ->indicateUsing(fn (array $data) => filled($data['barcode'] ?? null) ? __('Barcode').': '.$data['barcode'] : null),
                Filters::relation('category_id', 'category', 'Category'),
                SelectFilter::make('item_type')->label('Item Type')->options(ItemType::options()),
                TernaryFilter::make('is_active')->label('Active?'),
            ])
            ->recordActions([
                ActionGroup::make([
                    static::balanceAction('balanceMenu'),
                    EditAction::make()->modalWidth('5xl'),
                    DeleteAction::make(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('code');
    }

    public static function balanceAction(string $name = 'viewBalance'): Action
    {
        return Action::make($name)
            ->label('Balance')
            ->icon('heroicon-o-scale')
            ->modalHeading(fn (Item $record) => __('Balance').': '.$record->label)
            ->modalContent(fn (Item $record) => BalanceTable::forItem($record))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageItems::route('/'),
        ];
    }
}
