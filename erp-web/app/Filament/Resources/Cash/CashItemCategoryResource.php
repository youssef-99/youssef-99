<?php

namespace App\Filament\Resources\Cash;

use App\Enums\Module;
use App\Filament\Resources\Cash\CashItemCategoryResource\Pages\ManageCashItemCategories;
use App\Filament\Resources\ErpResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\ItemCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Cash Transactions > Item categories (فئات الأصناف) — /cash/item-categories.
 * A simplified view over the inventory item categories.
 */
class CashItemCategoryResource extends ErpResource
{
    protected static ?string $model = ItemCategory::class;

    protected static ?Module $module = Module::Cash;

    protected static ?string $permissionKey = 'cash.item-categories';

    protected static ?string $modelLabel = 'Item Category';

    protected static ?string $pluralModelLabel = 'Item Categories';

    protected static ?string $slug = 'cash/item-categories';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(ItemCategory::class, label: 'Category Code'),
            ...Fields::names('Category Name'),
            Fields::relation('parent_id', 'parent', 'Parent Category'),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Category Code'),
                Columns::name('Category Name'),
                TextColumn::make('parent.name_ar')->label('Parent Category')->formatStateUsing(fn (ItemCategory $record) => $record->parent?->name)->toggleable(),
                Columns::boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCashItemCategories::route('/'),
        ];
    }
}
