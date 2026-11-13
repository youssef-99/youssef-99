<?php

namespace App\Filament\Resources\Accounting;

use App\Enums\Module;
use App\Filament\Actions\DocumentActions;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\Accounting\FinancingContractResource\Pages;
use App\Filament\Resources\Accounting\FinancingContractResource\RelationManagers\InstallmentsRelationManager;
use App\Filament\Resources\Accounting\Shared\AccountingFields;
use App\Filament\Resources\ErpResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Account;
use App\Models\FinancingContract;
use App\Services\FinancingContractService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Murabaha financing contracts (عقود التمويل) — /accounting/financing-contracts.
 * Saving generates the monthly installment schedule; posting books the contract entry.
 */
class FinancingContractResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = FinancingContract::class;

    protected static ?Module $module = Module::Accounting;

    protected static ?string $permissionKey = 'accounting.financing-contracts';

    protected static ?string $documentType = null;

    protected static string $documentService = FinancingContractService::class;

    protected static string $printType = 'financing-contract';

    protected static ?string $modelLabel = 'Financing Contract';

    protected static ?string $pluralModelLabel = 'Financing Contracts';

    protected static ?string $slug = 'accounting/financing-contracts';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?int $navigationSort = 120;

    public static function form(Schema $schema): Schema
    {
        $calc = fn (TextInput $input): TextInput => $input->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => static::updateAmounts($get, $set));

        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                Fields::number(label: 'Contract No.')->unique(FinancingContract::class, 'number', ignoreRecord: true),
                Fields::branch(),
                AccountingFields::bank()->required(),
                Fields::date('contract_date', 'Contract Date'),
                $calc(Fields::money('murabaha_amount', 'Murabaha Amount')->required()->minValue(0.01)),
                $calc(Fields::percent('bank_profit_margin', 'Bank Profit Margin')),
                $calc(TextInput::make('installments_count')->label('No. of Installments')->integer()->required()->minValue(1)->maxValue(600)->default(12)),
                TextInput::make('installment_amount')->label('Installment Amount')->numeric()->readOnly()->default(0),
                TextInput::make('total_payable')
                    ->label('Total Payable')
                    ->numeric()
                    ->readOnly()
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(FinancingContractService::totalPayable((float) $get('murabaha_amount'), (float) $get('bank_profit_margin')))),
                DatePicker::make('first_installment_date')
                    ->label('First Installment Date')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required()
                    ->default(now()->addMonthNoOverflow()),
            ]),
            Section::make(__('Accounts'))->columns(['default' => 1, 'md' => 3])->schema([
                Fields::account('funding_account_id', 'fundingAccount', 'Funding Account')->required(),
                Fields::account('loan_account_id', 'loanAccount', 'Loan Account')->required()
                    ->default(fn (): ?int => Account::query()->where('number', '210401')->value('id')),
                Fields::account('interest_account_id', 'interestAccount', 'Profit Account')->required()
                    ->default(fn (): ?int => Account::query()->where('number', '520105')->value('id')),
            ]),
            Section::make()->columns(2)->schema([
                Textarea::make('notes')->label('Notes')->rows(2),
                Toggle::make('post_now')->label('Post Contract?')->dehydrated(false)->default(false)->visibleOn(['create', 'edit']),
            ]),
        ]);
    }

    public static function updateAmounts(Get $get, Set $set): void
    {
        $amount = (float) $get('murabaha_amount');
        $margin = (float) $get('bank_profit_margin');
        $count = (int) $get('installments_count');

        $set('total_payable', FinancingContractService::totalPayable($amount, $margin));
        $set('installment_amount', FinancingContractService::installmentAmount($amount, $margin, $count));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Contract No.')->searchable()->sortable(),
                Columns::date('contract_date', 'Contract Date'),
                Columns::name('Bank', 'bank'),
                Columns::money('murabaha_amount', 'Murabaha Amount'),
                TextColumn::make('bank_profit_margin')->label('Bank Profit Margin')->suffix('%')->numeric(2),
                TextColumn::make('installments_count')->label('No. of Installments')->alignCenter(),
                Columns::money('installment_amount', 'Installment Amount'),
                Columns::date('first_installment_date', 'First Installment Date')->toggleable(),
                Columns::posted(),
            ])
            ->filters([
                Filters::branch(),
                Filters::relation('bank_id', 'bank', 'Bank'),
                Filters::dateRange('contract_date'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DocumentActions::post(FinancingContractService::class, static::class),
                    DocumentActions::unpost(FinancingContractService::class, static::class),
                    DocumentActions::print('financing-contract'),
                    DeleteAction::make(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinancingContracts::route('/'),
            'create' => Pages\CreateFinancingContract::route('/create'),
            'view' => Pages\ViewFinancingContract::route('/{record}'),
            'edit' => Pages\EditFinancingContract::route('/{record}/edit'),
        ];
    }
}
