<?php

namespace App\Filament\Resources\Sales;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Sales\SalesPolicyResource\Pages\ManageSalesPolicies;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\SalesPolicy;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Sales policies (سياسات البيع) — /sales/sales-policies.
 * A policy gives a default line discount (and optionally a cost-plus price basis) to the customers that use it.
 */
class SalesPolicyResource extends ErpResource
{
    protected static ?string $model = SalesPolicy::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.sales-policies';

    protected static ?string $modelLabel = 'Sales Policy';

    protected static ?string $pluralModelLabel = 'Sales Policies';

    protected static ?string $slug = 'sales/sales-policies';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 10;

    /** @return array<string, string> */
    public static function priceBasisOptions(): array
    {
        return [
            'sale_price' => __('Item Sale Price'),
            'cost_plus' => __('Cost Plus Markup'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::relation('customer_id', 'customer', 'Customer'),
            Fields::code(SalesPolicy::class, label: 'Sales Policy Code'),
            ...Fields::names('Sales Policy Name'),
            Fields::percent('discount_percent', 'Discount %'),
            Select::make('price_basis')->label('Price Basis')->options(static::priceBasisOptions())->default('sale_price')->required()->live(),
            Fields::percent('markup_percent', 'Markup %')->maxValue(1000)->visible(fn (Get $get) => $get('price_basis') === 'cost_plus'),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Sales Policy Code'),
                Columns::name('Sales Policy Name'),
                TextColumn::make('customer.name_ar')->label('Customer')->formatStateUsing(fn (SalesPolicy $record) => $record->customer?->label)->placeholder('-'),
                TextColumn::make('discount_percent')->label('Discount %')->numeric(2)->suffix('%'),
                TextColumn::make('price_basis')->label('Price Basis')->formatStateUsing(fn (?string $state) => static::priceBasisOptions()[$state] ?? $state),
                Columns::boolean(),
            ])
            ->filters([
                Filters::relation('customer_id', 'customer', 'Customer'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSalesPolicies::route('/'),
        ];
    }
}
