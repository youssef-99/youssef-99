<?php

namespace App\Filament\Resources\Pos;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Pos\PointsCustomerResource\Pages\ManagePointsCustomers;
use App\Filament\Resources\Pos\PointsCustomerResource\Pages\ViewPointsCustomer;
use App\Filament\Resources\Pos\PointsCustomerResource\RelationManagers\TransactionsRelationManager;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\PointsCustomer;
use App\Services\DocumentNumber;
use App\Services\LoyaltyPointsService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Loyalty points customers (عملاء النقاط) — /sales/points-customers.
 */
class PointsCustomerResource extends ErpResource
{
    protected static ?string $model = PointsCustomer::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.points-customers';

    protected static ?string $modelLabel = 'Points Customer';

    protected static ?string $pluralModelLabel = 'Loyalty Points Customers';

    protected static ?string $slug = 'sales/points-customers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 86;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label('Customer Code')->required()->maxLength(50)
                ->default(fn () => DocumentNumber::nextCode(PointsCustomer::class))
                ->unique(PointsCustomer::class, 'code', ignoreRecord: true),
            TextInput::make('name')->label('Customer Name')->required()->maxLength(255),
            TextInput::make('mobile')->label('Mobile')->required()->tel()->maxLength(20)->unique(PointsCustomer::class, 'mobile', ignoreRecord: true),
            TextInput::make('delivery_code')->label('Delivery Code')->maxLength(50),
            TextInput::make('email')->label('Email')->email()->maxLength(255),
            TextInput::make('points_balance')->label('Points Balance')->numeric()->disabled()->dehydrated(false)->hiddenOn('create'),
            Fields::active(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('code')->label('Customer Code'),
                TextEntry::make('name')->label('Customer Name'),
                TextEntry::make('mobile')->label('Mobile'),
                TextEntry::make('delivery_code')->label('Delivery Code')->placeholder('-'),
                TextEntry::make('email')->label('Email')->placeholder('-'),
                TextEntry::make('points_balance')->label('Points Balance')->numeric(2)->weight('bold'),
                TextEntry::make('points_value')->label('Points Value')
                    ->state(fn (PointsCustomer $record) => number_format(LoyaltyPointsService::discountFor((float) $record->points_balance), 2)),
                IconEntry::make('is_active')->label('Active?')->boolean(),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Customer Code'),
                TextColumn::make('name')->label('Customer Name')->searchable()->sortable(),
                TextColumn::make('mobile')->label('Mobile')->searchable(),
                TextColumn::make('delivery_code')->label('Delivery Code')->searchable()->placeholder('-'),
                Columns::money('points_balance', 'Points Balance'),
                Columns::boolean(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getRelations(): array
    {
        return [TransactionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePointsCustomers::route('/'),
            'view' => ViewPointsCustomer::route('/{record}/view'),
        ];
    }
}
