<?php

namespace App\Filament\Resources\Sales;

use App\Enums\Module;
use App\Enums\Permission;
use App\Exceptions\ErpException;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Sales\CustomerResource\Pages;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Account;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\Settings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Customers (العملاء) — /sales/customers.
 * Every customer gets a sub-ledger account under the receivables parent and an opening-balance entry
 * (see CustomerService / PartyAccountService).
 */
class CustomerResource extends ErpResource
{
    protected static ?string $model = Customer::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.customers';

    protected static ?string $modelLabel = 'Customer';

    protected static ?string $pluralModelLabel = 'Customers';

    protected static ?string $slug = 'sales/customers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 20;

    /** @return array<string, string> */
    public static function customerTypeOptions(): array
    {
        return [
            'individual' => __('Individual'),
            'company' => __('Company'),
            'government' => __('Government'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        $grid = ['default' => 1, 'md' => 2, 'xl' => 3];

        return $schema->columns(1)->components([
            Section::make(__('Customer Data'))->columns($grid)->schema([
                Fields::code(Customer::class, label: 'Customer Code'),
                ...Fields::names('Customer Name'),
                Select::make('customer_type')->label('Customer Type')->options(static::customerTypeOptions())->default('company')->required(),
                Fields::relation('customer_group_id', 'customerGroup', 'Customer Group')->required(),
                Fields::relation('activity_id', 'activity', 'Customer Activity')->required(),
                Fields::relation('marketing_rep_id', 'marketingRep', 'Marketing Rep')->required(),
                Fields::relation('salesman_id', 'salesman', 'Salesman')->required(),
                Fields::relation('sales_policy_id', 'salesPolicy', 'Sales Policy'),
                Checkbox::make('is_taxable')->label('Taxable Customer?')->default(true),
                Checkbox::make('discount_eligible')->label('Eligible for Discounts?')->default(false),
                Checkbox::make('is_active')->label('Active?')->default(true),
                Textarea::make('description')->label('Description')->rows(2)->columnSpanFull(),
            ]),

            Section::make(__('Address'))->columns($grid)->schema([
                Fields::relation('country_id', 'country', 'Country')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('city_id', null);
                        $set('district_id', null);
                        $set('region_id', null);
                    }),
                Fields::relation('city_id', 'city', 'City', modifyQuery: fn (Builder $query, Get $get) => $query->where('country_id', $get('country_id')))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('district_id', null)),
                Fields::relation('district_id', 'district', 'District', modifyQuery: fn (Builder $query, Get $get) => $query->where('city_id', $get('city_id')))
                    ->required(),
                Fields::relation('region_id', 'region', 'Region', modifyQuery: fn (Builder $query, Get $get) => $query->where(fn (Builder $q) => $q->whereNull('country_id')->orWhere('country_id', $get('country_id'))))
                    ->required(),
                TextInput::make('address')->label('Address')->maxLength(255),
                TextInput::make('street_name')->label('Street Name')->maxLength(255),
                TextInput::make('building_no')->label('Building No.')->maxLength(20),
                TextInput::make('postal_code')->label('Postal Code')->maxLength(20),
                TextInput::make('additional_no')->label('Additional Address No.')->maxLength(20),
            ]),

            Section::make(__('Tax and Registration'))->columns($grid)->schema([
                TextInput::make('vat_no')->label('VAT No.')
                    ->maxLength(15)
                    ->regex('/^3\d{13}3$/')
                    ->validationMessages(['regex' => __('The VAT number must be 15 digits starting and ending with 3.')]),
                TextInput::make('commercial_register')->label('Commercial Register No.')->maxLength(50),
                DatePicker::make('register_expiry_date')->label('Register Expiry Date')->native(false)->displayFormat('d/m/Y'),
                TextInput::make('gln')->label('GLN')->maxLength(20),
            ]),

            Section::make(__('Contact'))->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                TextInput::make('email')->label('Email')->email()->maxLength(255),
                TextInput::make('phone')->label('Phone')->tel()->maxLength(30),
                TextInput::make('mobile')->label('Mobile')->tel()->maxLength(30),
                TextInput::make('fax')->label('Fax')->maxLength(30),
            ]),

            Section::make(__('Account and Credit'))->columns($grid)->schema([
                Fields::account('parent_account_id', 'parentAccount', 'Parent Account', postableOnly: false, modifyQuery: fn (Builder $q) => $q->where('is_postable', false))
                    ->default(fn () => Settings::account('receivables'))
                    ->preload(),
                Fields::account(label: 'Account', modifyQuery: fn (Builder $q) => $q->where('type', 'customer'))
                    ->markAsRequired()
                    ->placeholder(__('Auto-create a sub-ledger account'))
                    ->helperText(__('Leave empty to create the customer account automatically under the parent account.')),
                DatePicker::make('account_opening_date')->label('Account Opening Date')->native(false)->displayFormat('d/m/Y'),
                Fields::money('opening_debit', 'Opening Debit Balance')->required()->minValue(0),
                Fields::money('opening_credit', 'Opening Credit Balance')->required()->minValue(0),
                Fields::money('credit_limit', 'Credit Limit')->required()->minValue(0),
                TextInput::make('payment_term_days')->label('Payment Term')->integer()->minValue(0)->default(0)->required()->suffix(__('days')),
                DatePicker::make('last_reconciliation_date')->label('Last Reconciliation Date')->native(false)->displayFormat('d/m/Y')->visibleOn('view'),
            ]),

            Section::make(__('Notes and Documents'))->columns(2)->schema([
                Textarea::make('notes')->label('Notes')->rows(3),
                FileUpload::make('attachments')
                    ->label('Customer Document')
                    ->disk(CustomerService::ATTACHMENTS_DISK)
                    ->directory(CustomerService::ATTACHMENTS_DIRECTORY)
                    ->visibility('private')
                    ->multiple()
                    ->storeFileNamesIn('attachment_names')
                    ->downloadable()
                    ->openable()
                    ->maxSize(10240)
                    ->acceptedFileTypes(['application/pdf', 'image/*', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']),
            ]),

            Section::make(__('Accounts'))->visibleOn('view')->schema([
                RepeatableEntry::make('accounts_grid')
                    ->hiddenLabel()
                    ->state(fn (?Customer $record) => static::accountsGrid($record))
                    ->table([
                        TableColumn::make(__('Account Code')),
                        TableColumn::make(__('Account Name')),
                        TableColumn::make(__('Account No.')),
                    ])
                    ->schema([
                        TextEntry::make('code'),
                        TextEntry::make('name'),
                        TextEntry::make('number'),
                    ]),
            ]),
        ]);
    }

    /**
     * Accounts grid of the view page: the customer's sub-ledger account and its parent.
     *
     * @return array<int, array{code: string, name: string, number: string}>
     */
    public static function accountsGrid(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        return Account::query()
            ->whereKey(array_filter([$customer->account_id, $customer->parent_account_id]))
            ->orderByDesc('level')
            ->get()
            ->map(fn (Account $a) => ['code' => (string) $a->id, 'name' => $a->name, 'number' => (string) $a->number])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Customer Code'),
                Columns::name('Customer Name'),
                TextColumn::make('customerGroup.name_ar')->label('Customer Group')->formatStateUsing(fn (Customer $record) => $record->customerGroup?->name)->toggleable(),
                TextColumn::make('salesman.name_ar')->label('Salesman')->formatStateUsing(fn (Customer $record) => $record->salesman?->name)->toggleable(),
                TextColumn::make('commercial_register')->label('Commercial Register')->searchable()->toggleable(),
                TextColumn::make('vat_no')->label('VAT No.')->searchable()->toggleable(),
                TextColumn::make('mobile')->label('Mobile')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account.number')->label('Account No.')->toggleable(isToggledHiddenByDefault: true),
                Columns::boolean(),
            ])
            ->filters([
                Filters::relation('customer_group_id', 'customerGroup', 'Customer Group'),
                Filters::relation('salesman_id', 'salesman', 'Salesman'),
                Filters::relation('marketing_rep_id', 'marketingRep', 'Marketing Rep'),
                TernaryFilter::make('is_active')->label('Active?'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label(__('View Customer')),
                    EditAction::make(),
                    static::viewAttachmentsAction(),
                    static::deleteAction(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('code');
    }

    /** View Attachments (عرض المرفقات): lists the customer documents with temporary download links. */
    public static function viewAttachmentsAction(): Action
    {
        return Action::make('viewAttachments')
            ->label(__('View Attachments'))
            ->icon('heroicon-o-paper-clip')
            ->color('gray')
            ->modalHeading(fn (Customer $record) => __('Attachments').' - '.$record->name)
            ->modalContent(fn (Customer $record) => static::attachmentsHtml($record))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    public static function attachmentsHtml(Customer $customer): HtmlString
    {
        $files = CustomerService::attachments($customer);

        if ($files === []) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('No attachments.')).'</p>');
        }

        $items = array_map(fn (array $f) => $f['url']
            ? '<li><a class="text-primary-600 underline" target="_blank" rel="noopener" href="'.e($f['url']).'">'.e($f['name']).'</a></li>'
            : '<li class="text-gray-500">'.e($f['name']).' ('.e(__('file missing')).')</li>', $files);

        return new HtmlString('<ul class="list-disc space-y-1 ps-5 text-sm">'.implode('', $items).'</ul>');
    }

    /** Delete Customer (حذف العميل): blocked when the customer has documents or account movements. */
    public static function deleteAction(): Action
    {
        return Action::make('deleteCustomer')
            ->label(__('Delete Customer'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn () => static::userCan(Permission::Delete))
            ->action(function (Model $record, Action $action) {
                try {
                    CustomerService::delete($record);
                    Notification::make()->success()->title(__('Customer deleted'))->send();
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Cannot delete the customer'))->body($e->getMessage())->persistent()->send();
                    $action->halt();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
