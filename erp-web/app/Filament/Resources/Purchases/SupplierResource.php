<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\Module;
use App\Exceptions\ErpException;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Purchases\SupplierResource\Pages;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Currency;
use App\Models\Supplier;
use App\Services\SupplierService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Suppliers (الموردين) — /purchase/suppliers. Separate add / edit / view pages.
 * Saving creates the supplier sub-ledger account (when none is chosen) and the opening-balance entry.
 */
class SupplierResource extends ErpResource
{
    protected static ?string $model = Supplier::class;

    protected static ?Module $module = Module::Purchases;

    protected static ?string $permissionKey = 'purchases.suppliers';

    protected static ?string $modelLabel = 'Supplier';

    protected static ?string $pluralModelLabel = 'Suppliers';

    protected static ?string $slug = 'purchase/suppliers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 20;

    /** @return array<string, string> */
    public static function supplierTypes(): array
    {
        return ['local' => __('Local'), 'foreign' => __('Foreign')];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 2, 'xl' => 3])->schema([
                Fields::code(Supplier::class, label: 'Supplier Code'),
                ...Fields::names('Supplier Name'),
                Select::make('supplier_type')->label('Supplier Type')->options(static::supplierTypes())->default('local')->required(),
                Fields::relation('supplier_group_id', 'supplierGroup', 'Supplier Group')->required(),
                Fields::relation('currency_id', 'currency', 'Supplier Currency')
                    ->default(fn () => Currency::query()->where('is_default', true)->value('id') ?? Currency::query()->value('id'))
                    ->required(),
                Fields::account(label: 'Account')
                    ->required(fn (string $operation) => $operation !== 'create')
                    ->placeholder(__('Auto (new sub-ledger account)'))
                    ->helperText(fn (string $operation) => $operation === 'create' ? __('Leave blank to create the supplier account automatically under the suppliers parent account.') : null),
                TextInput::make('vat_no')->label('VAT No.')->maxLength(15)->regex('/^3\d{13}3$/')
                    ->validationMessages(['regex' => __('The VAT number must be 15 digits, starting and ending with 3.')]),
                TextInput::make('commercial_register')->label('Commercial Register No.')->maxLength(30),
                DatePicker::make('register_expiry_date')->label('Register Expiry Date')->native(false)->displayFormat('d/m/Y'),
                Textarea::make('description')->label('Description')->rows(2)->columnSpanFull(),
            ]),
            Section::make(__('Address'))->columns(['default' => 1, 'md' => 2, 'xl' => 3])->schema([
                Fields::relation('country_id', 'country', 'Country')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('city_id', null);
                        $set('district_id', null);
                    }),
                Fields::relation('city_id', 'city', 'City', modifyQuery: fn (Builder $query, Get $get) => $query->where('country_id', $get('country_id')))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('district_id', null)),
                Fields::relation('district_id', 'district', 'District', modifyQuery: fn (Builder $query, Get $get) => $query->where('city_id', $get('city_id')))
                    ->required(),
                TextInput::make('address')->label('Address')->maxLength(255),
                TextInput::make('street_name')->label('Street Name')->maxLength(255),
                TextInput::make('building_no')->label('Building No.')->maxLength(20),
                TextInput::make('postal_code')->label('Postal Code')->maxLength(10),
                TextInput::make('additional_no')->label('Additional Address No.')->maxLength(20),
                TextInput::make('gln')->label('GLN')->maxLength(13),
            ]),
            Section::make(__('Contact'))->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                TextInput::make('phone')->label('Phone')->tel()->maxLength(30),
                TextInput::make('mobile')->label('Mobile')->tel()->maxLength(30),
                TextInput::make('fax')->label('Fax')->tel()->maxLength(30),
                TextInput::make('email')->label('Email')->email()->maxLength(255),
            ]),
            Section::make(__('Balances'))->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                Fields::money('opening_debit', 'Opening Debit Balance')->required()->minValue(0),
                Fields::money('opening_credit', 'Opening Credit Balance')->required()->minValue(0)
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                        if ((float) $value > 0 && (float) $get('opening_debit') > 0) {
                            $fail(__('Enter either an opening debit or an opening credit balance, not both.'));
                        }
                    }),
                Fields::money('credit_limit', 'Credit Limit')->required()->minValue(0),
                TextInput::make('payment_term_days')->label('Payment Term')->numeric()->integer()->minValue(0)->default(0)->required()->suffix(__('days')),
                Fields::active(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Columns::code(label: 'Supplier Code'),
                Columns::name('Supplier Name'),
                TextColumn::make('supplierGroup.name_ar')->label('Supplier Group')->formatStateUsing(fn (Supplier $record) => $record->supplierGroup?->name)->toggleable(),
                TextColumn::make('supplier_type')->label('Supplier Type')->formatStateUsing(fn (?string $state) => static::supplierTypes()[$state] ?? $state)->toggleable(),
                TextColumn::make('account.number')->label('Account')->toggleable(),
                TextColumn::make('vat_no')->label('VAT No.')->searchable(),
                TextColumn::make('commercial_register')->label('Commercial Register')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mobile')->label('Mobile')->toggleable(),
                Columns::boolean(),
            ])
            ->filters([
                Filters::relation('supplier_group_id', 'supplierGroup', 'Supplier Group'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    static::deleteAction(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('code');
    }

    /** Deleting goes through SupplierService (blocks suppliers with documents, removes the opening entry). */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()->action(function (Supplier $record, Action $action) {
            try {
                SupplierService::delete($record);
                Notification::make()->success()->title(__('Deleted'))->send();
            } catch (ErpException $e) {
                Notification::make()->danger()->title(__('Cannot delete'))->body($e->getMessage())->persistent()->send();
                $action->halt();
            }
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/add'),
            'view' => Pages\ViewSupplier::route('/{record}/view'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
