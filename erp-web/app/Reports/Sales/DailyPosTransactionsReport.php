<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;
use Illuminate\Support\Carbon;

/**
 * Daily POS Transactions (حركات نقاط البيع اليومية): posted POS invoices and returns per day and cashier (user):
 * invoices, returns, sales, returns value, net, cash, card, amount received and change given.
 * Return amounts are deducted from cash / card.
 * Filters: Branches, User, From*, To*.
 */
class DailyPosTransactionsReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'daily-pos-transactions';

    protected static string $title = 'Daily POS Transactions';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
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
        $isReturn = SalesQuery::isReturn();
        $sign = SalesQuery::sign();

        $rows = SalesQuery::documents($filters, SalesQuery::POS_TYPES)
            ->leftJoin('users as us', 'us.id', '=', 'd.created_by')
            ->groupBy('d.date', 'd.created_by', 'us.name')
            ->select(['d.date', 'd.created_by', 'us.name as user_name'])
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 0 ELSE 1 END) as invoices")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 1 ELSE 0 END) as returns_count")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 0 ELSE d.grand_total END) as sales")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN d.grand_total ELSE 0 END) as returns")
            ->selectRaw("SUM(({$sign}) * d.cash_amount) as cash")
            ->selectRaw("SUM(({$sign}) * d.card_amount) as card")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 0 ELSE d.amount_received END) as received")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 0 ELSE d.change_due END) as change_due")
            ->orderBy('d.date')->orderBy('us.name')
            ->get();

        $result = $this->result(static::t('Including VAT'))
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape()
            ->columns([
                ReportColumn::date('date', 'Date'),
                ReportColumn::text('user', 'User'),
                ReportColumn::number('invoices', 'Invoices'),
                ReportColumn::number('returns_count', 'Returns Count'),
                ReportColumn::money('sales', 'Sales'),
                ReportColumn::money('returns', 'Returns'),
                ReportColumn::money('net', 'Net'),
                ReportColumn::money('cash', 'Cash'),
                ReportColumn::money('card', 'Card'),
                ReportColumn::money('received', 'Amount Received'),
                ReportColumn::money('change_due', 'Change'),
            ]);

        $keys = ['invoices', 'returns_count', 'sales', 'returns', 'net', 'cash', 'card', 'received', 'change_due'];
        foreach ($rows->groupBy(fn ($r) => Carbon::parse($r->date)->toDateString()) as $day => $dayRows) {
            foreach ($dayRows as $r) {
                $result->addRow([
                    'date' => $day,
                    'user' => $r->user_name ?? '-',
                    'invoices' => (int) $r->invoices,
                    'returns_count' => (int) $r->returns_count,
                    'sales' => round((float) $r->sales, 2),
                    'returns' => round((float) $r->returns, 2),
                    'net' => round((float) $r->sales - (float) $r->returns, 2),
                    'cash' => round((float) $r->cash, 2),
                    'card' => round((float) $r->card, 2),
                    'received' => round((float) $r->received, 2),
                    'change_due' => round((float) $r->change_due, 2),
                ]);
            }
        }

        return ReportTotals::footer($result, $keys, 'user');
    }
}
