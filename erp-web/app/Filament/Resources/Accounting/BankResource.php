<?php

namespace App\Filament\Resources\Accounting;

use App\Enums\Module;
use App\Filament\Resources\Accounting\BankResource\Pages\ManageBanks;
use App\Filament\Resources\ErpResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Bank;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Banks (البنوك) — /accounting/banks.
 */
class BankResource extends ErpResource
{
    protected static ?string $model = Bank::class;

    protected static ?Module $module = Module::Accounting;

    protected static ?string $permissionKey = 'accounting.banks';

    protected static ?string $modelLabel = 'Bank';

    protected static ?string $pluralModelLabel = 'Banks';

    protected static ?string $slug = 'accounting/banks';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Bank::class, label: 'Bank Code'),
            ...Fields::names('Bank Name'),
            TextInput::make('iban')->label('IBAN')->maxLength(34),
            TextInput::make('account_no')->label('Bank Account No.')->maxLength(50),
            TextInput::make('swift_code')->label('SWIFT Code')->maxLength(11),
            Fields::relation('city_id', 'city', 'City')->preload(false),
            TextInput::make('address')->label('Address')->maxLength(255),
            Fields::account(label: 'Bank Ledger Account'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Bank No.'),
                Columns::name('Bank Name'),
                TextColumn::make('account_no')->label('Bank Account No.')->searchable(),
                TextColumn::make('iban')->label('IBAN')->searchable(),
                Columns::name('City', 'city'),
                TextColumn::make('swift_code')->label('SWIFT Code')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBanks::route('/'),
        ];
    }
}
