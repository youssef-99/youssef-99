<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\CountryResource\Pages\ManageCountries;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Country;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Countries (الدول) — /general/countries.
 */
class CountryResource extends ErpResource
{
    protected static ?string $model = Country::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.countries';

    protected static ?string $modelLabel = 'Country';

    protected static ?string $pluralModelLabel = 'Countries';

    protected static ?string $slug = 'general/countries';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Country::class, label: 'Country No.'),
            TextInput::make('iso_code')
                ->label('ISO Code')
                ->maxLength(3)
                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null),
            ...Fields::names('Country Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Country No.'),
                Columns::name('Country Name'),
                TextColumn::make('iso_code')->label('ISO Code')->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->hidden(fn (Country $record): bool => $record->cities()->exists()),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCountries::route('/'),
        ];
    }
}
