<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\ActiveIngredientResource\Pages\ManageActiveIngredients;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\ActiveIngredient;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Active ingredients (المواد الفعالة) — /inventory/effective-materials.
 */
class ActiveIngredientResource extends ErpResource
{
    protected static ?string $model = ActiveIngredient::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.effective-materials';

    protected static ?string $modelLabel = 'Active Ingredient';

    protected static ?string $pluralModelLabel = 'Active Ingredients';

    protected static ?string $slug = 'inventory/effective-materials';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(ActiveIngredient::class, label: 'Code'),
            ...Fields::names('Active Ingredient Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Code'),
                Columns::name('Active Ingredient Name'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageActiveIngredients::route('/'),
        ];
    }
}
