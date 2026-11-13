<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\ItemGroupResource\Pages\ManageItemGroups;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\ItemGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Item groups (المجموعات) — /inventory/groups.
 */
class ItemGroupResource extends ErpResource
{
    protected static ?string $model = ItemGroup::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.groups';

    protected static ?string $modelLabel = 'Group';

    protected static ?string $pluralModelLabel = 'Groups';

    protected static ?string $slug = 'inventory/groups';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(ItemGroup::class, label: 'Group Code'),
            ...Fields::names('Group Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Group Code'),
                Columns::name('Group Name'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageItemGroups::route('/'),
        ];
    }
}
