<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Purchases\SupplierGroupResource\Pages\ManageSupplierGroups;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\SupplierGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Supplier groups (مجموعات الموردين) — /purchase/supplier-groups (modal add/edit).
 */
class SupplierGroupResource extends ErpResource
{
    protected static ?string $model = SupplierGroup::class;

    protected static ?Module $module = Module::Purchases;

    protected static ?string $permissionKey = 'purchases.supplier-groups';

    protected static ?string $modelLabel = 'Supplier Group';

    protected static ?string $pluralModelLabel = 'Supplier Groups';

    protected static ?string $slug = 'purchase/supplier-groups';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(SupplierGroup::class, label: 'Supplier Group Code'),
            ...Fields::names('Supplier Group Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Supplier Group Code'),
                Columns::name('Supplier Group Name'),
                TextColumn::make('suppliers_count')->label('Suppliers')->counts('suppliers')->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSupplierGroups::route('/'),
        ];
    }
}
