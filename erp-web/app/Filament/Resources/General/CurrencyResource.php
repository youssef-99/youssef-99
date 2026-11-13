<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\CurrencyResource\Pages\ManageCurrencies;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Currency;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Currencies (العملات) — /general/currencies.
 */
class CurrencyResource extends ErpResource
{
    protected static ?string $model = Currency::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.currencies';

    protected static ?string $modelLabel = 'Currency';

    protected static ?string $pluralModelLabel = 'Currencies';

    protected static ?string $slug = 'general/currencies';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Currency::class, label: 'Currency No.'),
            TextInput::make('iso_code')
                ->label('ISO Code')
                ->length(3)
                ->required()
                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),
            ...Fields::names('Currency Name'),
            TextInput::make('exchange_rate')
                ->label('Exchange Rate')
                ->numeric()
                ->required()
                ->default(1)
                ->minValue(0.000001)
                ->step(0.000001),
            Toggle::make('is_default')->label('Default Currency?'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Currency No.'),
                Columns::name('Currency Name'),
                TextColumn::make('exchange_rate')->label('Exchange Rate')->numeric(6)->alignEnd()->sortable(),
                TextColumn::make('iso_code')->label('ISO Code')->searchable(),
                Columns::boolean('is_default', 'Default Currency?'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->hidden(fn (Currency $record): bool => $record->is_default),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCurrencies::route('/'),
        ];
    }
}
