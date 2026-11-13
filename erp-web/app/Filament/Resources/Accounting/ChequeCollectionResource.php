<?php

namespace App\Filament\Resources\Accounting;

use App\Enums\Module;
use App\Filament\Actions\DocumentActions;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\Accounting\ChequeCollectionResource\Pages;
use App\Filament\Resources\ErpResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\ChequeCollection;
use App\Services\ChequeCollectionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Cheque collection (تحصيل الشيكات) — /accounting/cheques-collection.
 * Loads the cheques under collection due in the period; posting moves the ticked ones to the bank.
 */
class ChequeCollectionResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = ChequeCollection::class;

    protected static ?Module $module = Module::Accounting;

    protected static ?string $permissionKey = 'accounting.cheques-collection';

    protected static ?string $documentType = null;

    protected static string $documentService = ChequeCollectionService::class;

    protected static string $printType = 'cheque-collection';

    protected static ?string $modelLabel = 'Cheque Collection';

    protected static ?string $pluralModelLabel = 'Cheque Collections';

    protected static ?string $slug = 'accounting/cheques-collection';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?int $navigationSort = 110;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 2, 'xl' => 3])->schema([
                Fields::branch()->afterStateUpdated(fn (Get $get, Set $set) => static::loadRows($get, $set)),
                Fields::number(label: 'Cheque Collection No.'),
                Fields::date(label: 'Cheque Collection Date'),
                DatePicker::make('from_date')
                    ->label('From Date')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(now()->startOfMonth())
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => static::loadRows($get, $set)),
                DatePicker::make('to_date')
                    ->label('To Date')
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(now()->endOfMonth())
                    ->afterOrEqual('from_date')
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => static::loadRows($get, $set)),
                Fields::account('bank_account_id', 'bankAccount', 'Bank Account')->required(),
                Textarea::make('notes')->label('Notes')->rows(1)->columnSpanFull(),
            ]),
            Actions::make([
                Action::make('loadCheques')
                    ->label('Show Cheques')
                    ->icon('heroicon-o-magnifying-glass')
                    ->action(fn (Get $get, Set $set) => static::loadRows($get, $set)),
            ])->visibleOn(['create', 'edit']),
            static::chequesRepeater(),
            Section::make()->columns(['default' => 1, 'md' => 3])->schema([
                TextInput::make('total')->label('Total')->numeric()->readOnly()->default(0),
                Toggle::make('post_now')->label('Post Collection?')->dehydrated(false)->default(false)->visibleOn(['create', 'edit']),
            ]),
        ]);
    }

    public static function chequesRepeater(): Repeater
    {
        $readOnly = fn (string $name, string $label): TextInput => TextInput::make($name)->label($label)->readOnly();

        return Repeater::make('cheque_rows')
            ->label('Cheques')
            ->table([
                TableColumn::make(__('Collect?')),
                TableColumn::make(__('Receipt Voucher No.')),
                TableColumn::make(__('Cheque Status')),
                TableColumn::make(__('Debit Account')),
                TableColumn::make(__('Cheque Amount')),
                TableColumn::make(__('Cheque No.')),
                TableColumn::make(__('Due Date')),
                TableColumn::make(__('Bank')),
                TableColumn::make(__('Account Type')),
                TableColumn::make(__('Account')),
                TableColumn::make(__('Salesman')),
            ])
            ->schema([
                Checkbox::make('selected')
                    ->label('Collect?')
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => static::updateTotal($get, $set, '../../')),
                $readOnly('voucher_no', 'Receipt Voucher No.'),
                $readOnly('cheque_status', 'Cheque Status'),
                $readOnly('debit_account', 'Debit Account'),
                $readOnly('amount', 'Cheque Amount'),
                $readOnly('cheque_no', 'Cheque No.'),
                $readOnly('due_date', 'Due Date'),
                $readOnly('bank', 'Bank'),
                $readOnly('account_type', 'Account Type'),
                $readOnly('account', 'Account'),
                $readOnly('salesman', 'Salesman'),
                Hidden::make('id'),
            ])
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->defaultItems(0)
            ->afterStateHydrated(function (Repeater $component, ?ChequeCollection $record): void {
                if ($record) {
                    $component->state(static::keyed(ChequeCollectionService::rows(
                        $record->branch_id,
                        $record->from_date?->toDateString(),
                        $record->to_date?->toDateString(),
                        $record->cheque_line_ids,
                        $record,
                    )));
                }
            })
            ->columnSpanFull();
    }

    public static function loadRows(Get $get, Set $set): void
    {
        $rows = ChequeCollectionService::rows($get('branch_id') ? (int) $get('branch_id') : null, $get('from_date'), $get('to_date'));
        $set('cheque_rows', static::keyed($rows));
        static::updateTotal($get, $set);
    }

    public static function updateTotal(Get $get, Set $set, string $prefix = ''): void
    {
        $total = collect($get($prefix.'cheque_rows') ?? [])
            ->filter(fn (array $row): bool => (bool) ($row['selected'] ?? false))
            ->sum(fn (array $row): float => (float) ($row['amount'] ?? 0));

        $set($prefix.'total', round((float) $total, 2));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    protected static function keyed(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(string) Str::uuid()] = $row;
        }

        return $keyed;
    }

    /**
     * Turn the grid into the stored selection (used by the create/edit pages).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function dehydrateSelection(array $data): array
    {
        $data['cheque_line_ids'] = ChequeCollectionService::selectedIds($data['cheque_rows'] ?? []);
        unset($data['cheque_rows']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Cheque Collection No.')->searchable()->sortable(),
                Columns::date(label: 'Cheque Collection Date'),
                TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn (ChequeCollection $record) => $record->branch?->name)->toggleable(),
                Columns::date('from_date', 'From Date'),
                Columns::date('to_date', 'To Date'),
                TextColumn::make('bankAccount.name_ar')->label('Bank Account')->formatStateUsing(fn (ChequeCollection $record) => $record->bankAccount?->label),
                Columns::money('total', 'Total'),
                Columns::posted(),
            ])
            ->filters([
                Filters::year(),
                Filters::branch(),
                Filters::dateRange(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DocumentActions::post(ChequeCollectionService::class, static::class),
                    DocumentActions::unpost(ChequeCollectionService::class, static::class),
                    DocumentActions::print('cheque-collection'),
                    DeleteAction::make(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChequeCollections::route('/'),
            'create' => Pages\CreateChequeCollection::route('/add'),
            'view' => Pages\ViewChequeCollection::route('/{record}/view'),
            'edit' => Pages\EditChequeCollection::route('/{record}/edit'),
        ];
    }
}
