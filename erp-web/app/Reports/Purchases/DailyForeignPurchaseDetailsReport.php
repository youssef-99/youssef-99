<?php

namespace App\Reports\Purchases;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\PurchaseQuery;
use App\Reports\Support\ReportFields;
use App\Reports\Support\TradeFields;

/**
 * Daily Foreign Purchase Details (تفاصيل المشتريات الخارجية اليومية): posted foreign purchase invoices in the
 * "Purchases – Invoice Totals" layout, values in SAR (document exchange rate) plus currency, rate, the amount in
 * the invoice currency and shipping / purchase expenses (SAR).
 * Filters: Branches, Report Type*, Supplier, From*, To*.
 */
class DailyForeignPurchaseDetailsReport extends Report
{
    protected static string $group = 'purchase';

    protected static string $slug = 'daily-foreign-purchase-details';

    protected static string $title = 'Daily Foreign Purchase Details';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            ReportFields::reportType()->required(),
            ReportFields::supplier(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['report_type' => 'detail', 'from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        return PurchaseQuery::dailyReport(
            $this->result(static::t('Purchases – Invoice Totals')),
            $filters,
            ['foreign_purchase_invoice'],
            extraColumns: [
                ReportColumn::text('currency', 'Currency'),
                ReportColumn::number('exchange_rate', 'Exchange Rate', 4),
                ReportColumn::money('foreign_total', 'Amount in Currency'),
                ReportColumn::money('expenses', 'Purchase Expenses'),
            ],
            extraCells: fn (object $d): array => [
                'currency' => $d->currency,
                'exchange_rate' => (float) $d->exchange_rate,
                'foreign_total' => $d->foreign_total,
                'expenses' => $d->expenses,
            ],
        );
    }
}
