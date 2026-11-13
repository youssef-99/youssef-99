<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\RegionResource\Pages\ManageRegions;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Region;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Regions (المناطق) — /general/regions.
 */
class RegionResource extends ErpResource
{
    protected static ?string $model = Region::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.regions';

    protected static ?string $modelLabel = 'Region';

    protected static ?string $pluralModelLabel = 'Regions';

    protected static ?string $slug = 'general/regions';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static ?int $navigationSort = 63;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Region::class, label: 'Region No.'),
            Fields::relation('country_id', 'country', 'Country'),
            ...Fields::names('Region Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('country'))
            ->columns([
                Columns::code(label: 'Region No.'),
                Columns::name('Region Name'),
                Columns::name('Country', 'country'),
            ])
            ->filters([Filters::relation('country_id', 'country', 'Country')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRegions::route('/'),
        ];
    }
}
