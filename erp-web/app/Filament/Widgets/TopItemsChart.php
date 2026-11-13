<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Widget 1: top 10 items by quantity sold (posted sales, POS and cash sales minus returns).
 */
class TopItemsChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use UsesDashboardMetrics;

    protected static ?int $sort = 2;

    protected ?string $maxHeight = '320px';

    public function getHeading(): string
    {
        return __('Top 10 Items by Quantity');
    }

    public function getDescription(): string
    {
        return $this->periodDescription();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $items = $this->metrics()->topItems(10);

        return [
            'datasets' => [
                [
                    'label' => __('Quantity'),
                    'data' => $items->pluck('quantity')->all(),
                    'backgroundColor' => '#14b8a6',
                    'borderColor' => '#0f766e',
                    'borderWidth' => 1,
                    'borderRadius' => 4,
                ],
            ],
            'labels' => $items->pluck('name')->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true]],
        ];
    }
}
