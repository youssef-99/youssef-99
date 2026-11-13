<?php

namespace App\Reports\Sales;

use App\Models\PointsCustomer;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\TradeFields;
use Illuminate\Support\Facades\DB;

/**
 * Points Customers (عملاء النقاط): loyalty points per customer from the points transactions — opening balance,
 * points earned and redeemed in the period, closing balance, and the current balance on the customer card.
 * Filters: Points Customer, From*, To*, Only customers with movement.
 */
class PointsCustomersReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'points-customers';

    protected static string $title = 'Points Customers';

    public function filters(): array
    {
        return [
            TradeFields::pointsCustomer(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
            TradeFields::checkbox('with_movement', 'Only Customers with Movement'),
        ];
    }

    public function defaults(): array
    {
        return ['from' => ReportFields::fiscalYearStart(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $from = Ledger::date($filters['from']);
        $points = DB::table('points_transactions')
            ->where('date', '<', Ledger::nextDay($filters['to']))
            ->when($filters['points_customer_id'] ?? null, fn ($q, $id) => $q->where('points_customer_id', $id))
            ->groupBy('points_customer_id')
            ->select('points_customer_id')
            ->selectRaw('SUM(CASE WHEN date < ? THEN points ELSE 0 END) as opening', [$from])
            ->selectRaw('SUM(CASE WHEN date >= ? AND points > 0 THEN points ELSE 0 END) as earned', [$from])
            ->selectRaw('SUM(CASE WHEN date >= ? AND points < 0 THEN -points ELSE 0 END) as redeemed', [$from])
            ->get()
            ->keyBy('points_customer_id');

        $customers = PointsCustomer::query()
            ->when($filters['points_customer_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('code')
            ->get();

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->columns([
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('name', 'Customer'),
                ReportColumn::text('mobile', 'Mobile'),
                ReportColumn::text('delivery_code', 'Delivery Code'),
                ReportColumn::number('opening', 'Opening Balance', 2),
                ReportColumn::number('earned', 'Earned Points', 2),
                ReportColumn::number('redeemed', 'Redeemed Points', 2),
                ReportColumn::number('closing', 'Closing Balance', 2),
                ReportColumn::number('current', 'Current Balance', 2),
            ]);

        foreach ($customers as $customer) {
            $p = $points->get($customer->id);
            $earned = round((float) ($p->earned ?? 0), 2);
            $redeemed = round((float) ($p->redeemed ?? 0), 2);
            if (TradeFields::flag($filters, 'with_movement') && $earned == 0.0 && $redeemed == 0.0) {
                continue;
            }
            $opening = round((float) ($p->opening ?? 0), 2);
            $result->addRow([
                'code' => $customer->code,
                'name' => $customer->name,
                'mobile' => $customer->mobile,
                'delivery_code' => $customer->delivery_code,
                'opening' => $opening,
                'earned' => $earned,
                'redeemed' => $redeemed,
                'closing' => round($opening + $earned - $redeemed, 2),
                'current' => round((float) $customer->points_balance, 2),
            ]);
        }

        return ReportTotals::footer($result, ['opening', 'earned', 'redeemed', 'closing', 'current'], 'name');
    }
}
