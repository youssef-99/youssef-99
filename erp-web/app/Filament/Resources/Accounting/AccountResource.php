<?php

namespace App\Filament\Resources\Accounting;

use App\Enums\AccountType;
use App\Enums\Module;
use App\Exceptions\ErpException;
use App\Filament\Resources\Accounting\AccountResource\Pages\ManageAccounts;
use App\Filament\Resources\ErpResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Account;
use App\Services\AccountService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Chart of accounts (دليل الحسابات) — /accounting/accounts.
 * Tree by account number; only postable (leaf) accounts accept journal lines.
 */
class AccountResource extends ErpResource
{
    protected static ?string $model = Account::class;

    protected static ?Module $module = Module::Accounting;

    protected static ?string $permissionKey = 'accounting.accounts';

    protected static ?string $modelLabel = 'Account';

    protected static ?string $pluralModelLabel = 'Chart of Accounts';

    protected static ?string $slug = 'accounting/accounts';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('parent_id')
                ->label('Parent Account')
                ->relationship('parent', 'name_ar', fn ($query) => $query->orderBy('number'))
                ->getOptionLabelFromRecordUsing(fn (Account $record) => $record->number.' - '.$record->name)
                ->searchable(['number', 'name_ar', 'name_en'])
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    $parent = $state ? Account::query()->find($state) : null;
                    if (! $parent) {
                        return;
                    }
                    $set('level', $parent->level + 1);
                    $set('nature', $parent->nature);
                    $set('balance_sheet_item', $parent->balance_sheet_item);
                }),
            TextInput::make('number')
                ->label('Account No.')
                ->required()
                ->maxLength(30)
                ->unique(Account::class, 'number', ignoreRecord: true),
            TextInput::make('level')
                ->label('Level No.')
                ->required()
                ->integer()
                ->minValue(1)
                ->maxValue(10)
                ->default(1),
            ...Fields::names('Account Name'),
            Select::make('nature')
                ->label('Account Nature')
                ->options(AccountType::natureOptions())
                ->required()
                ->default('debit'),
            Select::make('balance_sheet_item')
                ->label('Balance Sheet Item')
                ->options(AccountType::balanceSheetOptions()),
            Select::make('type')
                ->label('Account Type')
                ->options(AccountType::options())
                ->required()
                ->default(AccountType::General->value),
            Toggle::make('is_postable')->label('Postable?')->default(true),
            Fields::active(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Account No.')
                    ->searchable()
                    ->sortable()
                    ->weight(fn (Account $record) => $record->is_postable ? null : FontWeight::Bold),
                TextColumn::make('name_ar')
                    ->label('Account Name')
                    ->formatStateUsing(fn (Account $record) => $record->name)
                    ->extraAttributes(fn (Account $record) => ['style' => 'padding-inline-start: '.(max(0, $record->level - 1) * 1.25).'rem'])
                    ->weight(fn (Account $record) => $record->is_postable ? null : FontWeight::Bold)
                    ->searchable(['name_ar', 'name_en']),
                TextColumn::make('nature')
                    ->label('Account Nature')
                    ->formatStateUsing(fn (?string $state) => AccountType::natureOptions()[$state] ?? $state)
                    ->badge()
                    ->color(fn (?string $state) => $state === 'credit' ? 'warning' : 'info'),
                TextColumn::make('level')->label('Level No.')->alignCenter()->sortable(),
                TextColumn::make('parent.number')->label('Parent Account')->toggleable(),
                TextColumn::make('type')->label('Account Type')->formatStateUsing(fn (?string $state) => AccountType::labelFor($state))->toggleable(),
                TextColumn::make('balance_sheet_item')
                    ->label('Balance Sheet Item')
                    ->formatStateUsing(fn (?string $state) => AccountType::balanceSheetOptions()[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                Columns::boolean('is_postable', 'Postable?')->toggleable(),
                Columns::boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('level')->label('Level No.')->options(array_combine(range(1, 6), range(1, 6))),
                SelectFilter::make('nature')->label('Account Nature')->options(AccountType::natureOptions()),
                SelectFilter::make('type')->label('Account Type')->options(AccountType::options()),
                TernaryFilter::make('is_postable')->label('Postable?'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->using(function (Account $record, DeleteAction $action): bool {
                        try {
                            AccountService::delete($record);
                        } catch (ErpException $e) {
                            Notification::make()->danger()->title(__('Cannot delete'))->body($e->getMessage())->persistent()->send();
                            $action->halt();
                        }

                        return true;
                    }),
            ])
            ->defaultSort('number')
            ->defaultPaginationPageOption(100);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccounts::route('/'),
        ];
    }
}
