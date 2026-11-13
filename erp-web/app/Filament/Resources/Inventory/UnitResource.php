<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\UnitResource\Pages\ManageUnits;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Unit;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Units (الوحدات) — /inventory/units.
 */
class UnitResource extends ErpResource
{
    protected static ?string $model = Unit::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.units';

    protected static ?string $modelLabel = 'Unit';

    protected static ?string $pluralModelLabel = 'Units';

    protected static ?string $slug = 'inventory/units';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Unit::class, label: 'Unit Code'),
            ...Fields::names('Unit Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Unit Code'),
                Columns::name('Unit Name'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUnits::route('/'),
        ];
    }
}
