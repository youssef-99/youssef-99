<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\ItemCategoryResource\Pages\ManageItemCategories;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\ItemCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Item categories (فئات الأصناف) — /inventory/item-categories.
 * Category Points % feeds loyalty points on POS invoices.
 */
class ItemCategoryResource extends ErpResource
{
    protected static ?string $model = ItemCategory::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.item-categories';

    protected static ?string $modelLabel = 'Item Category';

    protected static ?string $pluralModelLabel = 'Item Categories';

    protected static ?string $slug = 'inventory/item-categories';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::relation('parent_id', 'parent', 'Main Category', modifyQuery: fn (Builder $query, ?Model $record) => $query->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))),
            Fields::code(ItemCategory::class, label: 'Category Code'),
            ...Fields::names('Category Name'),
            Fields::percent('points_percent', 'Category Points %'),
            Toggle::make('is_sales_category')->label('Sales Category?')->default(true),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Category Code'),
                Columns::name('Category Name'),
                TextColumn::make('parent.name_ar')->label('Main Category')->formatStateUsing(fn ($record) => $record->parent?->name),
                TextColumn::make('points_percent')->label('Category Points %')->numeric(2)->suffix('%'),
                Columns::boolean('is_sales_category', 'Sales Category?'),
                Columns::boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_sales_category')->label('Sales Category?'),
                TernaryFilter::make('is_active')->label('Active?'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageItemCategories::route('/'),
        ];
    }
}
