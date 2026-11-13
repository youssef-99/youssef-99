<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Widget 2: sales performance by branch (net sales per branch over the period).
 */
class BranchSalesChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use UsesDashboardMetrics;

    /** @var array<int, string> */
    public const PALETTE = ['#0f766e', '#2563eb', '#d97706', '#9333ea', '#dc2626', '#059669', '#db2777', '#4b5563'];

    protected static ?int $sort = 3;

    protected ?string $maxHeight = '320px';

    public function getHeading(): string
    {
        return __('Sales Performance by Branch');
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
        $data = $this->metrics()->salesByBranch();
        $datasets = [];

        foreach ($data['branches'] as $index => $branch) {
            $color = self::PALETTE[$index % count(self::PALETTE)];
            $datasets[] = [
                'label' => $branch['name'].' ('.number_format($branch['total'], 2).')',
                'data' => $branch['values'],
                'backgroundColor' => $color,
                'borderColor' => $color,
                'borderRadius' => 3,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $data['labels'],
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
