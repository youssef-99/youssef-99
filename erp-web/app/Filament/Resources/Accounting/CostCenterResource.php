<?php

namespace App\Filament\Resources\Accounting;

use App\Enums\Module;
use App\Filament\Resources\Accounting\CostCenterResource\Pages\ManageCostCenters;
use App\Filament\Resources\ErpResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\CostCenter;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Cost centers (مراكز التكلفة) — /accounting/cost-centers.
 */
class CostCenterResource extends ErpResource
{
    protected static ?string $model = CostCenter::class;

    protected static ?Module $module = Module::Accounting;

    protected static ?string $permissionKey = 'accounting.cost-centers';

    protected static ?string $modelLabel = 'Cost Center';

    protected static ?string $pluralModelLabel = 'Cost Centers';

    protected static ?string $slug = 'accounting/cost-centers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(CostCenter::class, label: 'Cost Center Code'),
            Fields::relation('parent_id', 'parent', 'Parent Cost Center'),
            ...Fields::names('Cost Center Name'),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Cost Center Code'),
                Columns::name('Cost Center Name'),
                Columns::name('Parent Cost Center', 'parent'),
                Columns::boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCostCenters::route('/'),
        ];
    }
}
