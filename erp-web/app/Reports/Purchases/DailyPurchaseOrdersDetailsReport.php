<?php

namespace App\Reports\Purchases;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\PurchaseQuery;
use App\Reports\Support\ReportFields;
use App\Reports\Support\TradeFields;

/**
 * Daily Purchase Orders Details (تفاصيل أوامر الشراء اليومية): purchase orders (any status) in the
 * "Purchase Orders – Totals" layout of the daily purchase details, with the order status.
 * Filters (same as daily purchase details): Branches, Report Type*, Supplier, From*, To*.
 */
class DailyPurchaseOrdersDetailsReport extends Report
{
    protected static string $group = 'purchase';

    protected static string $slug = 'daily-purchase-orders-details';

    protected static string $title = 'Daily Purchase Orders Details';

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
            $this->result(static::t('Purchase Orders – Totals')),
            $filters,
            ['purchase_order'],
            posted: false,
            extraColumns: [ReportColumn::text('status', 'Status')],
            extraCells: fn (object $d): array => ['status' => static::t(str((string) $d->status)->headline()->toString())],
        );
    }
}
