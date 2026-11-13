<?php

namespace App\Filament\Resources\Sales;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Sales\CustomerGroupResource\Pages\ManageCustomerGroups;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\CustomerGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Customer groups (مجموعات العملاء) — /sales/customer-groups.
 */
class CustomerGroupResource extends ErpResource
{
    protected static ?string $model = CustomerGroup::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.customer-groups';

    protected static ?string $modelLabel = 'Customer Group';

    protected static ?string $pluralModelLabel = 'Customer Groups';

    protected static ?string $slug = 'sales/customer-groups';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(CustomerGroup::class, label: 'Customer Group Code'),
            ...Fields::names('Customer Group Name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Customer Group Code'),
                Columns::name('Customer Group Name'),
                TextColumn::make('customers_count')->label('Customers')->counts('customers')->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCustomerGroups::route('/'),
        ];
    }
}
