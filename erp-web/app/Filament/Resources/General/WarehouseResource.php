<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\WarehouseResource\Pages\ManageWarehouses;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Branch;
use App\Models\Warehouse;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Warehouses (المستودعات) — /general/stores. Every warehouse belongs to a branch.
 */
class WarehouseResource extends ErpResource
{
    protected static ?string $model = Warehouse::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.stores';

    protected static ?string $modelLabel = 'Warehouse';

    protected static ?string $pluralModelLabel = 'Warehouses';

    protected static ?string $slug = 'general/stores';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Warehouse::class, label: 'Warehouse Code'),
            Fields::relation('branch_id', 'branch', 'Branch Name')
                ->default(fn (): ?int => auth()->user()?->default_branch_id ?? Branch::query()->value('id'))
                ->required(),
            ...Fields::names('Warehouse'),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('branch'))
            ->columns([
                Columns::code(label: 'Warehouse Code'),
                Columns::name('Warehouse'),
                Columns::name('Branch Name', 'branch'),
                Columns::boolean(),
            ])
            ->filters([Filters::branch()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWarehouses::route('/'),
        ];
    }
}
