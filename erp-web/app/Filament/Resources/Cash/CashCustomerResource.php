<?php

namespace App\Filament\Resources\Cash;

use App\Enums\Module;
use App\Filament\Resources\Cash\CashCustomerResource\Pages\ManageCashCustomers;
use App\Filament\Resources\Cash\Shared\CashPartyFields;
use App\Filament\Resources\ErpResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\CashCustomer;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Cash customers (العملاء النقديين) — /cash/customers. Country, City and Region required.
 */
class CashCustomerResource extends ErpResource
{
    protected static ?string $model = CashCustomer::class;

    protected static ?Module $module = Module::Cash;

    protected static ?string $permissionKey = 'cash.customers';

    protected static ?string $modelLabel = 'Cash Customer';

    protected static ?string $pluralModelLabel = 'Cash Customers';

    protected static ?string $slug = 'cash/customers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(CashCustomer::class, label: 'Customer Code'),
            ...Fields::names('Customer Name'),
            CashPartyFields::vatNo(),
            TextInput::make('commercial_register')->label('Commercial Register No.')->maxLength(30),
            Textarea::make('description')->label('Description')->rows(2)->columnSpanFull(),
            ...CashPartyFields::geography(required: true),
            ...CashPartyFields::address(),
            ...CashPartyFields::phones(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Customer Code'),
                Columns::name('Customer Name'),
                TextColumn::make('vat_no')->label('VAT No.')->searchable(),
                TextColumn::make('city.name_ar')->label('City')->formatStateUsing(fn (CashCustomer $record) => $record->city?->name),
                TextColumn::make('region.name_ar')->label('Region')->formatStateUsing(fn (CashCustomer $record) => $record->region?->name)->toggleable(),
                TextColumn::make('mobile')->label('Mobile')->toggleable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCashCustomers::route('/'),
        ];
    }
}
