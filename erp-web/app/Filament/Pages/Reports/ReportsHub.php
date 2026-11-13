<?php

namespace App\Filament\Pages\Reports;

use App\Enums\Module;
use App\Filament\Concerns\HasErpPermission;
use App\Reports\ReportRegistry;
use App\Reports\Support\ReportLang;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Reports hub (التقارير) — /admin/reports: one card per report group listing its reports.
 * `?group=<slug>` limits the page to one group (used by the per-group sidebar items).
 * Reports not implemented yet are listed disabled.
 */
class ReportsHub extends Page
{
    use HasErpPermission;

    protected static ?Module $module = Module::Reports;

    protected static ?string $permissionKey = 'reports.hub';

    protected static ?string $slug = 'reports';

    protected static ?string $title = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?int $navigationSort = 1;

    protected string $view = 'reports.hub';

    #[Url]
    public ?string $group = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) $user && ($user->hasPermission(static::getPermissionKey().'.view') || ReportRegistry::canViewAny());
    }

    public static function getNavigationLabel(): string
    {
        return ReportLang::get('All Reports');
    }

    /** "All Reports" plus one item per report group linking to the filtered hub. */
    public static function getNavigationItems(): array
    {
        $items = [
            NavigationItem::make(static::getNavigationLabel())
                ->key(static::class)
                ->group(static::getNavigationGroup())
                ->icon(static::getNavigationIcon())
                ->sort(0)
                ->isActiveWhen(fn (): bool => request()->routeIs(static::getRouteName()) && blank(request()->query('group')))
                ->url(static::getUrl()),
        ];

        $sort = 1;
        foreach (ReportRegistry::hub() as $group => $node) {
            $items[] = NavigationItem::make($node['label'])
                ->key(static::class.'.'.$group)
                ->group(static::getNavigationGroup())
                ->icon($node['icon'])
                ->sort($sort++)
                ->isActiveWhen(fn (): bool => request()->query('group') === $group
                    || (request()->routeIs(ReportPage::getRouteName()) && request()->route('group') === $group))
                ->url(static::getUrl(['group' => $group]));
        }

        return $items;
    }

    public function getTitle(): string
    {
        return $this->group && isset(ReportRegistry::GROUPS[$this->group])
            ? ReportLang::get('Reports').' - '.ReportRegistry::groupLabel($this->group)
            : ReportLang::get('Reports');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $groups = ReportRegistry::hub(isset(ReportRegistry::GROUPS[(string) $this->group]) ? $this->group : null);

        return [
            'groups' => $groups,
            'urlFor' => fn (string $key): string => ReportPage::urlFor($key),
        ];
    }
}
