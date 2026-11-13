<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\ManufacturerResource\Pages\ManageManufacturers;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Manufacturer;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Manufacturing companies (الشركات المصنعة) — /inventory/manufacturing-companies.
 */
class ManufacturerResource extends ErpResource
{
    protected static ?string $model = Manufacturer::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.manufacturing-companies';

    protected static ?string $modelLabel = 'Manufacturer';

    protected static ?string $pluralModelLabel = 'Manufacturing Companies';

    protected static ?string $slug = 'inventory/manufacturing-companies';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Manufacturer::class, label: 'Code'),
            ...Fields::names('Manufacturer Name'),
            Fields::relation('country_id', 'country', 'Country'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Code'),
                Columns::name('Manufacturer Name'),
                Columns::name('Country', 'country'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageManufacturers::route('/'),
        ];
    }
}
