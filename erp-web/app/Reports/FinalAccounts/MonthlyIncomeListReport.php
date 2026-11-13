<?php

namespace App\Reports\FinalAccounts;

use App\Models\FiscalYear;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use Illuminate\Support\Carbon;

/**
 * Monthly Income Statement (قائمة الدخل الشهرية): the income statement with 12 month columns
 * of the chosen fiscal year plus a Total column. Filters: Year*, Branch.
 */
class MonthlyIncomeListReport extends IncomeListReport
{
    protected static string $slug = 'monthly-income-list';

    protected static string $title = 'Monthly Income Statement';

    public function filters(): array
    {
        return [
            ReportFields::year()->required(),
            ReportFields::branch(),
        ];
    }

    public function defaults(): array
    {
        return ['year' => now()->year];
    }

    public function run(array $filters): ReportResult
    {
        $year = (int) $filters['year'];
        $fiscal = FiscalYear::query()->where('year', $year)->first();
        $start = Carbon::parse($fiscal?->start_date ?? "{$year}-01-01")->startOfMonth();

        $periods = [];
        $columns = [ReportColumn::text('number', 'No.'), ReportColumn::text('name', 'Description')];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = 'm'.($i + 1);
            $periods[$key] = [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()];
            $columns[] = new ReportColumn($key, $month->locale(app()->getLocale())->translatedFormat('M Y'), 'money');
        }
        $columns[] = ReportColumn::money('total', 'Total');

        $result = $this->result()->info('Year', (string) $year)->landscape()->columns($columns);
        $this->build($result, $periods, $filters['branch_id'] ?? null, 'total');

        return $result;
    }
}
