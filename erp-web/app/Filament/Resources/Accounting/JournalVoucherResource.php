<?php

namespace App\Filament\Resources\Accounting;

use App\Enums\Module;
use App\Enums\Permission;
use App\Exceptions\ErpException;
use App\Filament\Actions\DocumentActions;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\Accounting\JournalVoucherResource\Pages;
use App\Filament\Resources\Accounting\Shared\AccountingFields;
use App\Filament\Resources\ErpResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\JournalEntry;
use App\Services\ManualJournalService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Journal vouchers (سندات القيد) — /accounting/journal-vouchers.
 * Lists every journal entry (filter by entry type); only manual entries can be added or edited.
 */
class JournalVoucherResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = JournalEntry::class;

    protected static ?Module $module = Module::Accounting;

    protected static ?string $permissionKey = 'accounting.journal-vouchers';

    protected static ?string $documentType = null;

    protected static string $documentService = ManualJournalService::class;

    protected static string $printType = 'journal';

    protected static ?string $modelLabel = 'Journal Voucher';

    protected static ?string $pluralModelLabel = 'Journal Vouchers';

    protected static ?string $slug = 'accounting/journal-vouchers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 40;

    /** @return array<string, string> نوع القيد */
    public static function entryTypes(): array
    {
        $types = [
            'manual' => 'Manual Entry',
            'opening' => 'Opening Balance',
            'sales' => 'Sales',
            'sales_return' => 'Sales Return',
            'pos' => 'POS',
            'purchase' => 'Purchases',
            'purchase_return' => 'Purchase Return',
            'receipt' => 'Receipt',
            'payment' => 'Payment',
            'notice' => 'Notice',
            'inventory' => 'Inventory',
            'cheque_collection' => 'Cheque Collection',
            'financing' => 'Financing Contract',
            'financing_installment' => 'Financing Installment',
            'closing' => 'Closing Entry',
        ];

        return array_map(fn (string $label): string => __($label), $types);
    }

    public static function entryTypeLabel(?string $type): ?string
    {
        return $type === null ? null : (static::entryTypes()[$type] ?? Str::headline($type));
    }

    public static function canEdit(Model $record): bool
    {
        return ! $record->is_posted && blank($record->source_type) && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->is_posted && blank($record->source_type) && parent::canDelete($record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                Fields::branch(),
                Fields::number(label: 'Voucher No.'),
                Fields::date(label: 'Voucher Date'),
                Select::make('entry_type')
                    ->label('Entry Type')
                    ->options(static::entryTypes())
                    ->default('manual')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('description')->label('Description')->rows(1)->columnSpanFull(),
            ]),
            static::linesRepeater(),
            Actions::make([static::importAction()])->visibleOn(['create', 'edit']),
            Section::make(__('Totals'))->schema([
                Grid::make(['default' => 1, 'md' => 4])->schema([
                    TextInput::make('total_debit')->label('Total Debit')->numeric()->readOnly()->default(0),
                    TextInput::make('total_credit')->label('Total Credit')->numeric()->readOnly()->default(0),
                    TextInput::make('difference')
                        ->label('Difference')
                        ->numeric()
                        ->readOnly()
                        ->dehydrated(false)
                        ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(round((float) $get('total_debit') - (float) $get('total_credit'), 2))),
                    Toggle::make('post_now')->label('Post Voucher?')->dehydrated(false)->default(false)->visibleOn(['create', 'edit']),
                ]),
            ])->columnSpanFull(),
        ]);
    }

    public static function linesRepeater(): Repeater
    {
        return Repeater::make('lines')
            ->label('Entry Lines')
            ->relationship('lines')
            ->table([
                TableColumn::make(__('Account Name'))->markAsRequired(),
                TableColumn::make(__('Account Type')),
                TableColumn::make(__('Debit (Dr)')),
                TableColumn::make(__('Credit (Cr)')),
                TableColumn::make(__('Description')),
                TableColumn::make(__('Salesman')),
                TableColumn::make(__('L/C Expenses')),
                TableColumn::make(__('Cost Center')),
                TableColumn::make(__('Voucher No.')),
                TableColumn::make(__('Document Date')),
            ])
            ->schema([
                AccountingFields::lineAccount(),
                AccountingFields::accountType(),
                static::amountInput('debit', 'Debit (Dr)', 'credit'),
                static::amountInput('credit', 'Credit (Cr)', 'debit'),
                TextInput::make('description')->label('Description')->maxLength(255),
                AccountingFields::salesman(),
                AccountingFields::accreditation(),
                AccountingFields::costCenter(),
                TextInput::make('document_no')->label('Voucher No.')->maxLength(50),
                DatePicker::make('document_date')->label('Document Date')->native(false)->displayFormat('d/m/Y'),
            ])
            ->defaultItems(2)
            ->addActionLabel(__('Add Line'))
            ->reorderable(false)
            ->cloneable()
            ->live()
            ->afterStateUpdated(fn (Get $get, Set $set) => static::updateTotals($get, $set))
            ->deleteAction(fn (Action $action) => $action->after(fn (Get $get, Set $set) => static::updateTotals($get, $set)))
            ->columnSpanFull();
    }

    protected static function amountInput(string $name, string $label, string $opposite): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->default(0)
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, Get $get, Set $set) use ($opposite): void {
                if ((float) $state > 0) {
                    $set($opposite, 0);
                }
                static::updateTotals($get, $set, '../../');
            });
    }

    /** Live Total Debit / Total Credit / Difference; $prefix is the path to the header ('' or '../../'). */
    public static function updateTotals(Get $get, Set $set, string $prefix = ''): void
    {
        $lines = collect($get($prefix.'lines') ?? []);
        $debit = round((float) $lines->sum(fn (array $line): float => (float) ($line['debit'] ?? 0)), 2);
        $credit = round((float) $lines->sum(fn (array $line): float => (float) ($line['credit'] ?? 0)), 2);

        $set($prefix.'total_debit', $debit);
        $set($prefix.'total_credit', $credit);
        $set($prefix.'difference', round($debit - $credit, 2));
    }

    /** "Import from Excel" (استيراد من Excel): appends lines read from an XLSX/CSV file. */
    public static function importAction(): Action
    {
        return Action::make('importExcel')
            ->label('Import from Excel')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalDescription(__('Columns: account no., debit, credit, description, cost center code.'))
            ->schema([
                FileUpload::make('file')
                    ->label('File')
                    ->disk('local')
                    ->directory('imports')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv',
                        'text/plain',
                        'application/csv',
                        'application/vnd.ms-excel',
                    ])
                    ->required(),
            ])
            ->action(function (array $data, Get $get, Set $set, Action $action): void {
                $disk = Storage::disk('local');
                $path = (string) $data['file'];

                try {
                    $imported = ManualJournalService::importLines($disk->path($path));
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Cannot import'))->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                } finally {
                    $disk->delete($path);
                }

                $set('lines', static::appendLines($get('lines') ?? [], $imported));
                static::updateTotals($get, $set);
                Notification::make()->success()->title(__(':count lines imported', ['count' => count($imported)]))->send();
            });
    }

    /**
     * Append imported lines to the repeater state, dropping empty rows.
     *
     * @param  array<array-key, array<string, mixed>>  $current
     * @param  array<int, array<string, mixed>>  $imported
     * @return array<string, array<string, mixed>>
     */
    public static function appendLines(array $current, array $imported): array
    {
        $lines = array_filter($current, fn (array $line): bool => filled($line['account_id'] ?? null) || (float) ($line['debit'] ?? 0) > 0 || (float) ($line['credit'] ?? 0) > 0);

        foreach ($imported as $line) {
            $lines[(string) Str::uuid()] = $line + ['account_type' => AccountingFields::accountTypeLabel($line['account_id'])];
        }

        return $lines;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Voucher No.')->searchable()->sortable(),
                Columns::date(label: 'Voucher Date'),
                TextColumn::make('entry_type')->label('Entry Type')->badge()->formatStateUsing(fn (?string $state) => static::entryTypeLabel($state)),
                TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn (JournalEntry $record) => $record->branch?->name)->toggleable(),
                TextColumn::make('description')->label('Description')->limit(50)->searchable()->toggleable(),
                Columns::money('total_debit', 'Total Debit'),
                Columns::money('total_credit', 'Total Credit'),
                Columns::posted(),
            ])
            ->filters([
                Filters::year(),
                Filters::branch(),
                SelectFilter::make('entry_type')->label('Entry Type')->options(static::entryTypes()),
                Filters::dateRange(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label(__('View Voucher')),
                    EditAction::make(),
                    DocumentActions::post(ManualJournalService::class, static::class),
                    static::unpostAction(),
                    DocumentActions::print('journal', label: 'Print Voucher'),
                    DeleteAction::make(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }

    /** Unpost for manual entries only (generated entries are unposted through their document). */
    public static function unpostAction(): Action
    {
        return DocumentActions::unpost(ManualJournalService::class, static::class)
            ->visible(fn (JournalEntry $record): bool => $record->is_posted && blank($record->source_type) && static::userCan(Permission::Post));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournalVouchers::route('/'),
            'create' => Pages\CreateJournalVoucher::route('/create'),
            'view' => Pages\ViewJournalVoucher::route('/{record}'),
            'edit' => Pages\EditJournalVoucher::route('/{record}/edit'),
        ];
    }
}
