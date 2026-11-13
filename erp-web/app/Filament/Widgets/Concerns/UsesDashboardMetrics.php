<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Data\DashboardMetrics;

/**
 * Dashboard widgets: page filters (period, tax) and visibility through the dashboard permission.
 */
trait UsesDashboardMetrics
{
    public static function canView(): bool
    {
        return Dashboard::canAccess();
    }

    protected function metrics(): DashboardMetrics
    {
        return DashboardMetrics::fromFilters($this->pageFilters);
    }

    protected function periodDescription(): string
    {
        $metrics = $this->metrics();

        return $metrics->from->format('Y-m-d').' - '.$metrics->to->format('Y-m-d').' · '.($metrics->withTax ? __('With tax') : __('Without tax'));
    }
}
