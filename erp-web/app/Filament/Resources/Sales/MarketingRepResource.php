<?php

namespace App\Filament\Resources\Sales;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Sales\MarketingRepResource\Pages\ManageMarketingReps;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\MarketingRep;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Marketing reps (مندوبي التسويق) — /sales/marketing-reps.
 */
class MarketingRepResource extends ErpResource
{
    protected static ?string $model = MarketingRep::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.marketing-reps';

    protected static ?string $modelLabel = 'Marketing Rep';

    protected static ?string $pluralModelLabel = 'Marketing Reps';

    protected static ?string $slug = 'sales/marketing-reps';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 35;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(MarketingRep::class),
            Fields::branch()->required(false)->live(false),
            ...Fields::names(),
            TextInput::make('mobile')->label('Mobile')->tel()->maxLength(30),
            Fields::percent('commission_percent', 'Commission %'),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(),
                Columns::name(),
                TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn (MarketingRep $record) => $record->branch?->name)->placeholder('-'),
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
            'index' => ManageMarketingReps::route('/'),
        ];
    }
}
