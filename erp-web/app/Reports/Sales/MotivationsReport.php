<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\TradeFields;
use App\Services\MotivationService;

/**
 * Staff Incentives (الحوافز): incentives earned per selling user in the period, computed by
 * {@see MotivationService::earned()} (amount per unit or % of sales before VAT; returns deduct).
 * Filters: Branch, User, From*, To*.
 */
class MotivationsReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'motivations';

    protected static string $title = 'Staff Incentives';

    public function filters(): array
    {
        return [
            ReportFields::branch(),
            TradeFields::user(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $rows = MotivationService::earned(
            $filters['from'],
            $filters['to'],
            filled($filters['branch_id'] ?? null) ? (int) $filters['branch_id'] : null,
            filled($filters['user_id'] ?? null) ? (int) $filters['user_id'] : null,
        );

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->columns([
                ReportColumn::text('user', 'User'),
                ReportColumn::number('quantity', 'Quantity', 3),
                ReportColumn::money('sales', 'Sales Excl. VAT'),
                ReportColumn::money('incentive', 'Incentive'),
            ]);

        foreach ($rows as $row) {
            $result->addRow(['user' => $row['user_name'], 'quantity' => $row['quantity'], 'sales' => $row['sales'], 'incentive' => $row['incentive']]);
        }

        return ReportTotals::footer($result, ['quantity', 'sales', 'incentive'], 'user');
    }
}
