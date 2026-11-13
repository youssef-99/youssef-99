<?php

namespace App\Filament\Resources\Pos;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Pos\DrugDoseResource\Pages\ManageDrugDoses;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\DrugDose;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Drug doses (جرعات الأدوية) — /sales/drug-doses. Dose text AR / EN, frequency, optional item.
 */
class DrugDoseResource extends ErpResource
{
    protected static ?string $model = DrugDose::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.drug-doses';

    protected static ?string $modelLabel = 'Drug Dose';

    protected static ?string $pluralModelLabel = 'Drug Doses';

    protected static ?string $slug = 'sales/drug-doses';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static ?int $navigationSort = 83;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::code(DrugDose::class, label: 'Dose Code'),
            TextInput::make('frequency')->label('Frequency')->maxLength(255)->placeholder(__('e.g. 3 times a day')),
            ...Fields::names('Dose'),
            Fields::relation('item_id', 'item', 'Item'),
            Fields::active(),
            Textarea::make('instructions')->label('Instructions')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Dose Code'),
                Columns::name('Dose'),
                TextColumn::make('frequency')->label('Frequency')->searchable(),
                TextColumn::make('item.name_ar')->label('Item')->formatStateUsing(fn ($record) => $record->item?->label)->placeholder('-'),
                Columns::boolean(),
            ])
            ->filters([Filters::relation('item_id', 'item', 'Item')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDrugDoses::route('/'),
        ];
    }
}
