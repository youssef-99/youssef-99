<?php

namespace App\Filament\Pages\General;

use App\Enums\Module;
use App\Filament\Concerns\HasErpPermission;
use App\Filament\Resources\General\EgsUnitResource;
use App\Models\Account;
use App\Models\Company;
use App\Services\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * System Settings (إعدادات النظام) — /general/settings.
 * Default posting accounts used by the posting engine plus company, VAT and ZATCA options,
 * all stored through the Settings service (key/value, cached).
 *
 * @property-read Schema $form
 */
class SystemSettings extends Page
{
    use HasErpPermission;

    protected static ?Module $module = Module::General;

    protected static ?string $permissionKey = 'general.settings';

    protected static ?string $title = 'System Settings';

    protected static ?string $slug = 'general/settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 100;

    /** Parent accounts are allowed for these keys (party sub-accounts are created under them). */
    public const PARENT_ACCOUNT_KEYS = ['accounts.receivables', 'accounts.payables'];

    /**
     * Non-account settings and their defaults.
     *
     * @return array<string, mixed>
     */
    public static function optionDefaults(): array
    {
        return [
            'company.default_id' => null,
            'vat.default_rate' => config('erp.default_vat_rate', 15),
            'vat.prices_include_tax' => false,
            'zatca.enabled' => (bool) config('erp.zatca.enabled', true),
            'zatca.environment' => config('erp.zatca.environment', 'sandbox'),
            'zatca.submission_mode' => config('erp.zatca.submission_mode', 'on_post'),
            'zatca.auto_resubmit' => true,
        ];
    }

    /** Settings stored as "1" / "0". */
    protected const BOOLEAN_KEYS = ['vat.prices_include_tax', 'zatca.enabled', 'zatca.auto_resubmit'];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $values = [];

        foreach (array_keys(Settings::ACCOUNT_KEYS) as $key) {
            $id = Settings::get($key);
            $values[$key] = $id ? (int) $id : null;
        }

        foreach (static::optionDefaults() as $key => $default) {
            $value = Settings::get($key, $default);
            $values[$key] = in_array($key, self::BOOLEAN_KEYS, true) ? (bool) $value : $value;
        }

        $this->form->fill(Arr::undot($values));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make(__('Default Accounts'))
                            ->icon('heroicon-o-calculator')
                            ->columns(2)
                            ->schema($this->accountFields()),
                        Tab::make(__('Company and VAT'))
                            ->icon('heroicon-o-building-office-2')
                            ->columns(2)
                            ->schema([
                                Select::make('company.default_id')
                                    ->label('Default Company')
                                    ->helperText(__('Printed on documents and sent to ZATCA when a branch has no company.'))
                                    ->options(fn (): array => Company::query()->orderBy('code')->get()->mapWithKeys(fn (Company $company) => [$company->id => $company->label])->all())
                                    ->searchable(),
                                TextInput::make('vat.default_rate')
                                    ->label('Default VAT %')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->required(),
                                Toggle::make('vat.prices_include_tax')
                                    ->label('Selling prices include VAT?'),
                            ]),
                        Tab::make(__('ZATCA E-Invoicing'))
                            ->icon('heroicon-o-qr-code')
                            ->columns(2)
                            ->schema([
                                Toggle::make('zatca.enabled')
                                    ->label('Enable ZATCA e-invoicing?')
                                    ->columnSpanFull(),
                                Select::make('zatca.environment')
                                    ->label('Environment')
                                    ->options(fn (): array => EgsUnitResource::environments())
                                    ->required(),
                                Select::make('zatca.submission_mode')
                                    ->label('Submission Mode')
                                    ->options([
                                        'on_post' => __('Submit when the invoice is posted'),
                                        'manual' => __('Submit manually from the ZATCA pages'),
                                    ])
                                    ->required(),
                                Toggle::make('zatca.auto_resubmit')
                                    ->label('Resubmit failed invoices automatically?'),
                                Section::make(__('EGS Units'))
                                    ->compact()
                                    ->columnSpanFull()
                                    ->description(__('CSID onboarding, invoice counter and hash chain are kept per EGS unit.'))
                                    ->footerActions([
                                        Action::make('egsUnits')
                                            ->label('EGS Units')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->url(fn (): string => EgsUnitResource::getUrl())
                                            ->visible(fn (): bool => EgsUnitResource::canViewAny()),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<Select>
     */
    protected function accountFields(): array
    {
        $accounts = Account::query()->where('is_active', true)->orderBy('number')->get(['id', 'number', 'name_ar', 'name_en', 'is_postable']);
        $postable = $accounts->where('is_postable', true)->mapWithKeys(fn (Account $a) => [$a->id => $a->number.' - '.$a->name])->all();
        $all = $accounts->mapWithKeys(fn (Account $a) => [$a->id => $a->number.' - '.$a->name])->all();

        $fields = [];
        foreach (Settings::ACCOUNT_KEYS as $key => $label) {
            $options = in_array($key, self::PARENT_ACCOUNT_KEYS, true) ? $all : $postable;

            $fields[] = Select::make($key)
                ->label($label)
                ->options($options)
                ->searchable();
        }

        return $fields;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        DB::transaction(function () use ($state): void {
            foreach (array_keys(Settings::ACCOUNT_KEYS) as $key) {
                $value = data_get($state, $key);
                Settings::set($key, filled($value) ? (string) $value : null);
            }

            foreach (array_keys(static::optionDefaults()) as $key) {
                $value = data_get($state, $key);
                Settings::set($key, in_array($key, self::BOOLEAN_KEYS, true) ? ($value ? '1' : '0') : (filled($value) ? (string) $value : null));
            }
        });

        Notification::make()->success()->title(__('Saved'))->send();
    }
}
