<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\General\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\General\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\General\CompanyResource\Pages\ViewCompany;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\Company;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Companies (الشركات) — /general/companies.
 * The company header (CR, VAT no., national address, logo) is printed on invoices and used by ZATCA.
 */
class CompanyResource extends ErpResource
{
    protected static ?string $model = Company::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.companies';

    protected static ?string $modelLabel = 'Company';

    protected static ?string $pluralModelLabel = 'Companies';

    protected static ?string $slug = 'general/companies';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('Company Data'))
                ->columns(3)
                ->schema([
                    Fields::code(Company::class, label: 'Company Code'),
                    ...Fields::names('Company Name'),
                    TextInput::make('commercial_register')->label('Commercial Register')->maxLength(50),
                    TextInput::make('membership_no')->label('Membership No.')->maxLength(50),
                    TextInput::make('vat_no')
                        ->label('Tax (VAT) No.')
                        ->maxLength(15)
                        ->regex('/^3\d{13}3$/')
                        ->validationMessages(['regex' => __('The VAT number must be 15 digits starting and ending with 3.')]),
                    Fields::active(),
                ]),
            Section::make(__('Contact'))
                ->columns(3)
                ->schema([
                    TextInput::make('phone')->label('Phone')->tel()->maxLength(50),
                    TextInput::make('mobile')->label('Mobile')->tel()->maxLength(50),
                    TextInput::make('fax')->label('Fax')->maxLength(50),
                    TextInput::make('email')->label('Email')->email()->maxLength(255),
                    TextInput::make('address')->label('Address')->maxLength(255)->columnSpan(2),
                ]),
            Section::make(__('National Address'))
                ->description(__('Required by ZATCA for standard tax invoices.'))
                ->columns(3)
                ->schema([
                    TextInput::make('street_name')->label('Street Name')->maxLength(255),
                    TextInput::make('building_no')->label('Building No.')->maxLength(4),
                    TextInput::make('additional_no')->label('Additional No.')->maxLength(4),
                    TextInput::make('district_name')->label('District')->maxLength(255),
                    TextInput::make('city_name')->label('City')->maxLength(255),
                    TextInput::make('postal_code')->label('Postal Code')->maxLength(5),
                ]),
            Section::make(__('Logo'))
                ->schema([
                    FileUpload::make('logo')
                        ->label('Logo')
                        ->image()
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                        ->disk('public')
                        ->directory('company-logos')
                        ->maxSize(2048),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')->label('Logo')->disk('public')->circular()->toggleable(isToggledHiddenByDefault: true),
                Columns::code(label: 'Company Code'),
                Columns::name('Company Name'),
                TextColumn::make('commercial_register')->label('Commercial Register')->searchable(),
                TextColumn::make('membership_no')->label('Membership No.')->searchable(),
                TextColumn::make('vat_no')->label('Tax (VAT) No.')->searchable(),
                TextColumn::make('phone')->label('Phone'),
                TextColumn::make('mobile')->label('Mobile'),
                TextColumn::make('address')->label('Address')->limit(40),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/add'),
            'view' => ViewCompany::route('/{record}/view'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
