<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\Module;
use App\Enums\Permission;
use App\Filament\Actions\DocumentActions;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Purchases\AccreditationFeeResource\Pages\ManageAccreditationFees;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Accreditation;
use App\Models\AccreditationFee;
use App\Models\Bank;
use App\Services\AccreditationService;
use App\Services\DocumentNumber;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Letter of credit fees (مصاريف الاعتمادات) — /purchase/accreditation-fees (modal add/edit).
 * Bank charges, shipping, customs, insurance... Posting: Dr L/C account / Cr bank (or the chosen account).
 */
class AccreditationFeeResource extends ErpResource
{
    protected static ?string $model = AccreditationFee::class;

    protected static ?Module $module = Module::Purchases;

    protected static ?string $permissionKey = 'purchases.accreditation-fees';

    protected static array $extraPermissions = [Permission::Post];

    protected static ?string $modelLabel = 'Accreditation Fee';

    protected static ?string $pluralModelLabel = 'Accreditation Fees';

    protected static ?string $slug = 'purchase/accreditation-fees';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 70;

    /** @return array<string, string> */
    public static function feeTypeOptions(): array
    {
        return array_map(fn (string $label) => __($label), AccreditationService::feeTypes());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('accreditation_id')
                ->label('Letter of Credit')
                ->relationship('accreditation', 'number', fn (Builder $query) => $query->where('status', 'open'))
                ->getOptionLabelFromRecordUsing(fn (Accreditation $record) => $record->label)
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                    $bankId = $state ? Accreditation::query()->whereKey($state)->value('bank_id') : null;
                    if ($bankId && ! $get('credit_account_id')) {
                        $set('credit_account_id', Bank::query()->whereKey($bankId)->value('account_id'));
                    }
                }),
            Fields::branch(),
            TextInput::make('number')->label('Fee No.')->maxLength(50)
                ->default(fn () => DocumentNumber::nextCode(AccreditationFee::class, 'number')),
            Fields::date(),
            Select::make('fee_type')->label('Fee Type')->options(static::feeTypeOptions())->default('bank_charges')->required(),
            Fields::money('amount', 'Amount')->required()->minValue(0.01),
            Fields::account('credit_account_id', 'creditAccount', 'Credit Account (Bank)')
                ->helperText(__('Defaults to the account of the L/C bank.')),
            Textarea::make('description')->label('Description')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Fee No.')->searchable()->sortable(),
                Columns::date(),
                TextColumn::make('accreditation.number')->label('L/C No.')->searchable(),
                TextColumn::make('accreditation.supplier.name_ar')->label('Supplier')->formatStateUsing(fn (AccreditationFee $record) => $record->accreditation?->supplier?->name)->toggleable(),
                TextColumn::make('fee_type')->label('Fee Type')->formatStateUsing(fn (?string $state) => static::feeTypeOptions()[$state] ?? $state),
                Columns::money('amount', 'Amount'),
                TextColumn::make('creditAccount.number')->label('Credit Account (Bank)')->formatStateUsing(fn (AccreditationFee $record) => $record->creditAccount ? $record->creditAccount->number.' - '.$record->creditAccount->name : null)->toggleable(),
                TextColumn::make('journalEntry.number')->label('Voucher No.')->toggleable(),
                Columns::posted(),
            ])
            ->filters([
                Filters::relation('accreditation_id', 'accreditation', 'Letter of Credit')->getOptionLabelFromRecordUsing(fn (Accreditation $record) => $record->label),
                SelectFilter::make('fee_type')->label('Fee Type')->options(static::feeTypeOptions()),
                Filters::branch(),
                Filters::posted(),
                Filters::dateRange(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DocumentActions::post(AccreditationService::class, static::class),
                    DocumentActions::unpost(AccreditationService::class, static::class),
                    DeleteAction::make(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function canEdit(Model $record): bool
    {
        return ! $record->is_posted && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->is_posted && parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccreditationFees::route('/'),
        ];
    }
}
