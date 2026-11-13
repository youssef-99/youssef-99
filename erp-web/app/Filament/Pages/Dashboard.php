<?php

namespace App\Filament\Pages;

use App\Enums\Module;
use App\Filament\Concerns\HasErpPermission;
use App\Filament\Widgets\BranchSalesChart;
use App\Filament\Widgets\Data\DashboardMetrics;
use App\Filament\Widgets\SalesStatsOverview;
use App\Filament\Widgets\TopItemsChart;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * Dashboard (لوحة التحكم) /dashboard: top 10 items by quantity, sales performance by branch and
 * stat cards, filtered by period (default last 7 days) and with / without tax.
 */
class Dashboard extends BaseDashboard
{
    use HasErpPermission;
    use HasFiltersForm;

    protected static ?Module $module = Module::Dashboard;

    protected static ?string $permissionKey = 'dashboard.dashboard';

    protected static string $routePath = '/dashboard';

    protected static ?string $slug = 'dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -100;

    public static function getRoutePath(Panel $panel): string
    {
        return static::$routePath;
    }

    /** Top-level sidebar item (no group), so it comes first and is the panel home. */
    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['md' => 3])
                ->schema([
                    DatePicker::make('from')
                        ->label('From Date')
                        ->default(fn (): string => now()->subDays(DashboardMetrics::DEFAULT_DAYS - 1)->toDateString())
                        ->maxDate(fn (Get $get): ?string => $get('to'))
                        ->live(),
                    DatePicker::make('to')
                        ->label('To Date')
                        ->default(fn (): string => now()->toDateString())
                        ->minDate(fn (Get $get): ?string => $get('from'))
                        ->live(),
                    ToggleButtons::make('tax')
                        ->label('Tax')
                        ->options([
                            'with' => __('With tax'),
                            'without' => __('Without tax'),
                        ])
                        ->default('with')
                        ->inline()
                        ->grouped()
                        ->live(),
                ]),
        ])->columns(1);
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            SalesStatsOverview::class,
            TopItemsChart::class,
            BranchSalesChart::class,
        ];
    }
}
