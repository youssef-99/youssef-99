<?php

namespace App\Filament\Resources\General;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\General\EgsUnitResource\Pages\ManageEgsUnits;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\EgsUnit;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ZATCA EGS units (وحدات الفوترة الإلكترونية) — /general/egs-units.
 * One active unit per branch keeps the ICV / previous-invoice-hash chain and the CSIDs.
 */
class EgsUnitResource extends ErpResource
{
    protected static ?string $model = EgsUnit::class;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.egs-units';

    protected static ?string $modelLabel = 'EGS Unit';

    protected static ?string $pluralModelLabel = 'EGS Units';

    protected static ?string $slug = 'general/egs-units';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 90;

    /**
     * ZATCA invoice transaction type codes (KSA-2 positions 1-2).
     *
     * @return array<string, string>
     */
    public static function invoicingTypes(): array
    {
        return [
            '1000' => __('Standard (B2B)'),
            '0100' => __('Simplified (B2C)'),
            '1100' => __('Standard and Simplified'),
        ];
    }

    /** @return array<string, string> */
    public static function environments(): array
    {
        return [
            'sandbox' => __('Sandbox (Developer Portal)'),
            'simulation' => __('Simulation'),
            'production' => __('Production'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::branch()->label('Branch Name'),
            TextInput::make('unit_name')->label('Unit Name')->required()->maxLength(255),
            Select::make('invoicing_type')->label('Invoicing Type')->options(fn (): array => static::invoicingTypes())->default('1100')->required(),
            TextInput::make('industry_sector')->label('Industry Sector')->maxLength(255)->default('Pharmacy'),
            TextInput::make('location')->label('Location')->maxLength(255),
            TextInput::make('serial_number')->label('Serial Number')->maxLength(255)->placeholder('1-ERP|2-1.0|3-UUID'),
            Select::make('environment')->label('Environment')->options(fn (): array => static::environments())->default('sandbox')->required(),
            Fields::active(),
            Section::make(__('ZATCA Onboarding (CSID)'))
                ->columnSpanFull()
                ->collapsible()
                ->collapsed()
                ->columns(2)
                ->schema([
                    Textarea::make('private_key')
                        ->label('Private Key')
                        ->rows(3)
                        ->columnSpanFull()
                        ->formatStateUsing(fn (): ?string => null)
                        ->placeholder(fn (?EgsUnit $record): ?string => filled($record?->private_key) ? __('Saved — leave blank to keep') : null)
                        ->dehydrated(fn (?string $state): bool => filled($state)),
                    Textarea::make('csr')->label('CSR')->rows(3)->columnSpanFull(),
                    Textarea::make('compliance_csid')->label('Compliance CSID')->rows(2),
                    static::secretInput('compliance_secret', 'Compliance Secret'),
                    Textarea::make('production_csid')->label('Production CSID')->rows(2),
                    static::secretInput('production_secret', 'Production Secret'),
                    TextInput::make('last_icv')->label('Last Invoice Counter (ICV)')->disabled()->dehydrated(false),
                    TextInput::make('last_invoice_hash')->label('Last Invoice Hash')->disabled()->dehydrated(false),
                ]),
        ]);
    }

    /** Password field that keeps the stored value when left blank on edit. */
    protected static function secretInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->password()
            ->revealable()
            ->formatStateUsing(fn (): ?string => null)
            ->placeholder(fn (?EgsUnit $record): ?string => filled($record?->{$name}) ? __('Saved — leave blank to keep') : null)
            ->dehydrated(fn (?string $state): bool => filled($state));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('branch'))
            ->columns([
                Columns::name('Branch Name', 'branch'),
                TextColumn::make('unit_name')->label('Unit Name')->searchable()->sortable(),
                TextColumn::make('invoicing_type')->label('Invoicing Type')->formatStateUsing(fn (?string $state): string => static::invoicingTypes()[$state] ?? (string) $state),
                TextColumn::make('industry_sector')->label('Industry Sector'),
                TextColumn::make('location')->label('Location'),
                TextColumn::make('environment')->label('Environment')->badge()->formatStateUsing(fn (?string $state): string => static::environments()[$state] ?? (string) $state),
                IconColumn::make('onboarded')
                    ->label('Onboarded?')
                    ->state(fn (EgsUnit $record): bool => filled($record->production_csid) || filled($record->compliance_csid))
                    ->boolean(),
                TextColumn::make('last_icv')->label('Last Invoice Counter (ICV)')->numeric()->alignEnd(),
                Columns::boolean(),
            ])
            ->filters([Filters::branch()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEgsUnits::route('/'),
        ];
    }
}
