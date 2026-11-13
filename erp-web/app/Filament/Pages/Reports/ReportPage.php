<?php

namespace App\Filament\Pages\Reports;

use App\Reports\Report;
use App\Reports\ReportRegistry;
use App\Reports\Support\ReportLang;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * Report filter page — /admin/reports/<group>/<report>. One page class serves every report in
 * {@see ReportRegistry}: it shows the report's filters (required ones marked *), Back (عودة) and
 * Show Report (عرض التقرير), which validates the filters and opens the viewer in a new tab.
 * Access needs the report's own permission "reports.<group>.<report>.view".
 *
 * @property-read Schema $form
 */
class ReportPage extends Page
{
    protected static ?string $slug = 'reports/{group}/{report}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public string $group = '';

    #[Locked]
    public string $report = '';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected ?Report $reportInstance = null;

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'reports.show';
    }

    /** Any user who can open at least one report (per-report access is checked in mount()). */
    public static function canAccess(): bool
    {
        return ReportRegistry::canViewAny();
    }

    /**
     * Defaults to the first report the user can open when no group / report is given
     * (navigation, smoke tests).
     *
     * @param  array<mixed>  $parameters
     */
    public static function getUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        if (! isset($parameters['group'], $parameters['report'])) {
            $key = collect(array_keys(ReportRegistry::reports()))->first(fn (string $k) => ReportRegistry::canView($k))
                ?? array_key_first(ReportRegistry::reports()) ?? 'accounts/account-statement';
            [$parameters['group'], $parameters['report']] = explode('/', $key, 2);
        }

        return parent::getUrl($parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
    }

    public static function urlFor(string $key): string
    {
        [$group, $report] = explode('/', $key, 2);

        return static::getUrl(['group' => $group, 'report' => $report]);
    }

    public function mount(string $group, string $report): void
    {
        $this->group = $group;
        $this->report = $report;

        abort_unless(ReportRegistry::exists($this->key()) && ReportRegistry::find($this->key()), 404);
        abort_unless(ReportRegistry::canView($this->key()), 403);

        $this->form->fill($this->getReport()->defaults());
    }

    public function key(): string
    {
        return "{$this->group}/{$this->report}";
    }

    public function getReport(): Report
    {
        return $this->reportInstance ??= ReportRegistry::make($this->key()) ?? abort(404);
    }

    public function getTitle(): string|Htmlable
    {
        return ReportRegistry::title($this->key());
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            ReportsHub::getUrl() => ReportLang::get('Reports'),
            ReportsHub::getUrl(['group' => $this->group]) => ReportRegistry::groupLabel($this->group),
            ReportRegistry::title($this->key()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(['default' => 1, 'md' => 2, 'xl' => 3])->schema($this->getReport()->filters()),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('showReport')
                ->footer([
                    Actions::make([
                        Action::make('showReport')
                            ->label(ReportLang::get('Show Report'))
                            ->icon('heroicon-o-document-magnifying-glass')
                            ->submit('showReport'),
                        Action::make('back')
                            ->label(ReportLang::get('Back'))
                            ->color('gray')
                            ->icon('heroicon-o-arrow-uturn-right')
                            ->url(ReportsHub::getUrl(['group' => $this->group])),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    /** Validate the filters and open the viewer in a new browser tab. */
    public function showReport(): void
    {
        abort_unless(ReportRegistry::canView($this->key()), 403);

        $filters = array_filter($this->form->getState(), fn ($value) => $value !== null && $value !== '' && $value !== []);
        $url = $this->viewerUrl($filters);

        $this->js('window.open('.json_encode($url).', "_blank")');
    }

    /** @param  array<string, mixed>  $filters */
    public function viewerUrl(array $filters): string
    {
        $query = array_map(fn ($value) => is_bool($value) ? (int) $value : $value, $filters);

        return route('report.viewer', ['report' => $this->key()] + $query);
    }
}
