<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\CityResource\Pages\ManageCities;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\City;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Cities (المدن) — /general/cities. Filter: Country.
 */
class CityResource extends ErpResource
{
    protected static ?string $model = City::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.cities';

    protected static ?string $modelLabel = 'City';

    protected static ?string $pluralModelLabel = 'Cities';

    protected static ?string $slug = 'general/cities';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 61;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(City::class, label: 'City No.'),
            Fields::relation('country_id', 'country', 'Country')->required(),
            ...Fields::names('City Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('country'))
            ->columns([
                Columns::code(label: 'City No.'),
                Columns::name('City Name'),
                Columns::name('Country', 'country'),
            ])
            ->filters([Filters::relation('country_id', 'country', 'Country')])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->hidden(fn (City $record): bool => $record->districts()->exists()),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCities::route('/'),
        ];
    }
}
