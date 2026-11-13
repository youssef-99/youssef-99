<?php

namespace App\Filament\Resources\Cash;

use App\Enums\Module;
use App\Filament\Resources\Cash\CashSupplierResource\Pages\ManageCashSuppliers;
use App\Filament\Resources\Cash\Shared\CashPartyFields;
use App\Filament\Resources\ErpResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\CashSupplier;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Cash suppliers (الموردين النقديين) — /cash/suppliers. VAT No. required; no account or opening balance.
 */
class CashSupplierResource extends ErpResource
{
    protected static ?string $model = CashSupplier::class;

    protected static ?Module $module = Module::Cash;

    protected static ?string $permissionKey = 'cash.suppliers';

    protected static ?string $modelLabel = 'Cash Supplier';

    protected static ?string $pluralModelLabel = 'Cash Suppliers';

    protected static ?string $slug = 'cash/suppliers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(CashSupplier::class, label: 'Supplier Code'),
            ...Fields::names('Supplier Name'),
            CashPartyFields::vatNo()->required(),
            Textarea::make('description')->label('Description')->rows(2)->columnSpanFull(),
            ...CashPartyFields::address(),
            ...CashPartyFields::phones(),
            ...CashPartyFields::geography(required: false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Supplier Code'),
                Columns::name('Supplier Name'),
                TextColumn::make('vat_no')->label('VAT No.')->searchable(),
                TextColumn::make('city.name_ar')->label('City')->formatStateUsing(fn (CashSupplier $record) => $record->city?->name)->toggleable(),
                TextColumn::make('mobile')->label('Mobile')->toggleable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCashSuppliers::route('/'),
        ];
    }
}
