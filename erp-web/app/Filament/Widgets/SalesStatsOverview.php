<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Stat cards: net sales, invoices count, returns, VAT for the selected period.
 */
class SalesStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;
    use UsesDashboardMetrics;

    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Sales Summary');
    }

    protected function getDescription(): ?string
    {
        return $this->periodDescription();
    }

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $summary = $this->metrics()->summary();

        return [
            Stat::make(__('Total Sales'), number_format($summary['sales'], 2))
                ->description(__('Invoices minus returns'))
                ->icon('heroicon-o-banknotes')
                ->color('primary'),
            Stat::make(__('Invoices Count'), number_format($summary['invoices_count']))
                ->description(number_format($summary['invoices_total'], 2))
                ->icon('heroicon-o-document-text')
                ->color('success'),
            Stat::make(__('Returns'), number_format($summary['returns_total'], 2))
                ->description(__(':count returns', ['count' => $summary['returns_count']]))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger'),
            Stat::make(__('Output VAT'), number_format($summary['vat'], 2))
                ->description(__('VAT on sales minus returns'))
                ->icon('heroicon-o-receipt-percent')
                ->color('warning'),
        ];
    }
}
