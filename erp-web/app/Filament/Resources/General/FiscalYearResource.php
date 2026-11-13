<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\FiscalYearResource\Pages\ManageFiscalYears;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\FiscalYear;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Fiscal years (السنوات المالية) — /general/fiscal-years.
 */
class FiscalYearResource extends ErpResource
{
    protected static ?string $model = FiscalYear::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.fiscal-years';

    protected static ?string $modelLabel = 'Fiscal Year';

    protected static ?string $pluralModelLabel = 'Fiscal Years';

    protected static ?string $slug = 'general/fiscal-years';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 95;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('year')
                ->label('Year')
                ->integer()
                ->required()
                ->minValue(2000)
                ->maxValue(2100)
                ->default(fn (): int => (int) (FiscalYear::query()->max('year') ?? now()->year - 1) + 1)
                ->unique(FiscalYear::class, 'year', ignoreRecord: true)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, $state): void {
                    if (is_numeric($state)) {
                        $set('start_date', "{$state}-01-01");
                        $set('end_date', "{$state}-12-31");
                    }
                }),
            Toggle::make('is_closed')->label('Closed?')->inline(false),
            Fields::date('start_date', 'Start Date')->default(fn (): string => ((int) (FiscalYear::query()->max('year') ?? now()->year - 1) + 1).'-01-01'),
            Fields::date('end_date', 'End Date')->default(fn (): string => ((int) (FiscalYear::query()->max('year') ?? now()->year - 1) + 1).'-12-31')->afterOrEqual('start_date'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')->label('Year')->sortable()->searchable(),
                Columns::date('start_date', 'Start Date'),
                Columns::date('end_date', 'End Date'),
                Columns::boolean('is_closed', 'Closed?'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('year', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFiscalYears::route('/'),
        ];
    }
}
