<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\DistrictResource\Pages\ManageDistricts;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\City;
use App\Models\Country;
use App\Models\District;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Districts (الأحياء) — /general/districts. Filters: Country, City.
 */
class DistrictResource extends ErpResource
{
    protected static ?string $model = District::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.districts';

    protected static ?string $modelLabel = 'District';

    protected static ?string $pluralModelLabel = 'Districts';

    protected static ?string $slug = 'general/districts';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 62;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(District::class, label: 'District No.'),
            Fields::relation('city_id', 'city', 'City')->required(),
            ...Fields::names('District Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('city.country'))
            ->columns([
                Columns::code(label: 'District No.'),
                Columns::name('District Name'),
                Columns::name('City', 'city'),
                Columns::name('Country', 'city.country'),
            ])
            ->filters([
                Filter::make('location')
                    ->schema([
                        Select::make('country_id')
                            ->label('Country')
                            ->options(fn (): array => Country::query()->orderBy('code')->get()->mapWithKeys(fn (Country $country) => [$country->id => $country->name])->all())
                            ->live()
                            ->searchable(),
                        Select::make('city_id')
                            ->label('City')
                            ->options(fn (Get $get): array => City::query()
                                ->when($get('country_id'), fn (Builder $query, $countryId) => $query->where('country_id', $countryId))
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (City $city) => [$city->id => $city->name])
                                ->all())
                            ->searchable(),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['city_id'] ?? null, fn (Builder $q, $cityId) => $q->where('city_id', $cityId))
                        ->when($data['country_id'] ?? null, fn (Builder $q, $countryId) => $q->whereHas('city', fn (Builder $city) => $city->where('country_id', $countryId)))),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDistricts::route('/'),
        ];
    }
}
