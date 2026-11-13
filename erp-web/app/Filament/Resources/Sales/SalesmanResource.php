<?php

namespace App\Filament\Resources\Sales;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Sales\SalesmanResource\Pages\ManageSalesmen;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Salesman;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Salesmen (مندوبي المبيعات) — /sales/salesmen.
 */
class SalesmanResource extends ErpResource
{
    protected static ?string $model = Salesman::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.salesmen';

    protected static ?string $modelLabel = 'Salesman';

    protected static ?string $pluralModelLabel = 'Salesmen';

    protected static ?string $slug = 'sales/salesmen';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(Salesman::class),
            Fields::branch()->live(false),
            ...Fields::names(),
            TextInput::make('mobile')->label('Mobile')->tel()->maxLength(30),
            Fields::percent('commission_percent', 'Commission %'),
            Fields::account(label: 'Commission Account'),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(),
                Columns::name(),
                TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn (Salesman $record) => $record->branch?->name),
                TextColumn::make('mobile')->label('Mobile')->searchable(),
                TextColumn::make('commission_percent')->label('Commission %')->numeric(2)->suffix('%')->sortable(),
                TextColumn::make('customers_count')->label('Customers')->counts('customers')->sortable(),
                Columns::boolean(),
            ])
            ->filters([
                Filters::branch(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSalesmen::route('/'),
        ];
    }
}
