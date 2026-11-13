<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\BranchResource\Pages\ManageBranches;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Branches (الفروع) — /general/branches.
 * Reference implementation of a simple master-data resource (modal add/edit).
 */
class BranchResource extends ErpResource
{
    protected static ?string $model = Branch::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.branches';

    protected static ?string $modelLabel = 'Branch';

    protected static ?string $pluralModelLabel = 'Branches';

    protected static ?string $slug = 'general/branches';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::relation('company_id', 'company', 'Company')->required(),
            Fields::code(Branch::class, label: 'Branch Code'),
            ...Fields::names('Branch Name'),
            TextInput::make('phone')->label('Phone')->tel(),
            TextInput::make('address')->label('Address'),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Branch Code'),
                Columns::name('Branch Name'),
                TextColumn::make('company.name_ar')->label('Company')->formatStateUsing(fn ($record) => $record->company?->name),
                TextColumn::make('phone')->label('Phone'),
                Columns::boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                // Deleting a branch cascades to its warehouses and documents: only allow it for unused branches.
                DeleteAction::make()->hidden(fn (Branch $record): bool => $record->warehouses()->exists()
                    || SalesDocument::query()->where('branch_id', $record->id)->exists()
                    || PurchaseDocument::query()->where('branch_id', $record->id)->exists()
                    || JournalEntry::query()->where('branch_id', $record->id)->exists()),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBranches::route('/'),
        ];
    }
}
