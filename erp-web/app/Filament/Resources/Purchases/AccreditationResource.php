<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\Module;
use App\Enums\Permission;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Purchases\AccreditationResource\Pages\ManageAccreditations;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Account;
use App\Models\Accreditation;
use App\Models\Currency;
use App\Services\AccreditationService;
use App\Services\DocumentNumber;
use App\Services\Settings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Letters of credit (الاعتمادات المستندية) — /purchase/accreditations (modal add/edit, open / close).
 * Fees are posted from Accreditation Fees; foreign purchase invoices linked to an L/C credit its account.
 */
class AccreditationResource extends ErpResource
{
    protected static ?string $model = Accreditation::class;

    protected static ?Module $module = Module::Purchases;

    protected static ?string $permissionKey = 'purchases.accreditations';

    protected static ?string $modelLabel = 'Letter of Credit';

    protected static ?string $pluralModelLabel = 'Letters of Credit';

    protected static ?string $slug = 'purchase/accreditations';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('number')->label('L/C No.')->required()->maxLength(50)
                ->default(fn () => DocumentNumber::nextCode(Accreditation::class, 'number'))
                ->unique(Accreditation::class, 'number', ignoreRecord: true),
            Fields::relation('bank_id', 'bank', 'Bank')->required(),
            Fields::account('lc_account_id', 'lcAccount', 'L/C Account')
                ->default(fn () => Account::query()->where('type', 'lc')->where('is_postable', true)->value('id'))
                ->required(),
            Fields::account('inventory_account_id', 'inventoryAccount', 'Inventory Account')
                ->default(fn () => Settings::account('inventory'))
                ->required(),
            Fields::relation('supplier_id', 'supplier', 'Supplier')->required(),
            Fields::relation('country_id', 'country', 'Country'),
            Fields::relation('currency_id', 'currency', 'Currency')->required()
                ->default(fn () => Currency::query()->where('is_default', true)->value('id')),
            Fields::money('value', 'L/C Value')->required()->minValue(0),
            Fields::money('bank_charges', 'Bank Charges')->minValue(0),
            Fields::date('open_date', 'Open Date'),
            DatePicker::make('close_date')->label('Close Date')->native(false)->displayFormat('d/m/Y')->afterOrEqual('open_date'),
            Textarea::make('notes')->label('Notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('L/C No.')->searchable()->sortable(),
                Columns::name('Bank', 'bank'),
                TextColumn::make('lcAccount.number')->label('L/C Account')->formatStateUsing(fn (Accreditation $record) => $record->lcAccount ? $record->lcAccount->number.' - '.$record->lcAccount->name : null)->toggleable(),
                TextColumn::make('inventoryAccount.number')->label('Inventory Account')->formatStateUsing(fn (Accreditation $record) => $record->inventoryAccount ? $record->inventoryAccount->number.' - '.$record->inventoryAccount->name : null)->toggleable(),
                Columns::name('Supplier', 'supplier'),
                TextColumn::make('country.name_ar')->label('Country')->formatStateUsing(fn (Accreditation $record) => $record->country?->name)->toggleable(),
                TextColumn::make('currency.name_ar')->label('Currency')->formatStateUsing(fn (Accreditation $record) => $record->currency?->name),
                Columns::money('value', 'L/C Value'),
                Columns::money('bank_charges', 'Bank Charges'),
                TextColumn::make('fees_sum_amount')->label('Posted Fees')->sum(['fees' => fn (Builder $query) => $query->where('is_posted', true)], 'amount')->numeric(2)->toggleable(),
                Columns::date('open_date', 'Open Date'),
                Columns::date('close_date', 'Close Date'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'closed' ? __('Closed') : __('Open'))
                    ->color(fn (?string $state) => $state === 'closed' ? 'gray' : 'success'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(['open' => __('Open'), 'closed' => __('Closed')]),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('close')
                        ->label(__('Close L/C'))
                        ->icon('heroicon-o-lock-closed')
                        ->color('warning')
                        ->schema([Fields::date('close_date', 'Close Date')])
                        ->visible(fn (Accreditation $record) => $record->isOpen() && static::userCan(Permission::Edit))
                        ->action(function (Accreditation $record, array $data) {
                            AccreditationService::close($record, $data['close_date']);
                            Notification::make()->success()->title(__('The letter of credit was closed.'))->send();
                        }),
                    Action::make('reopen')
                        ->label(__('Reopen L/C'))
                        ->icon('heroicon-o-lock-open')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (Accreditation $record) => ! $record->isOpen() && static::userCan(Permission::Edit))
                        ->action(fn (Accreditation $record) => AccreditationService::reopen($record)),
                    DeleteAction::make()->visible(fn (Accreditation $record) => ! $record->purchaseDocuments()->exists() && ! $record->fees()->where('is_posted', true)->exists()),
                ])->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->purchaseDocuments()->exists() && parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccreditations::route('/'),
        ];
    }
}
