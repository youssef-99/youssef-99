<?php

namespace App\Reports\Purchases;

use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\PurchaseQuery;
use App\Reports\Support\ReportFields;
use App\Reports\Support\TradeFields;

/**
 * Daily Purchase Details (تفاصيل المشتريات اليومية), output "Purchases – Invoice Totals" (المشتريات - إجمالي الفواتير):
 * posted local purchase invoices, cash purchase invoices and their returns (negative).
 * Detail: Invoice (No., Date, Type), Supplier (No., Name, VAT No.), Value (Total, Discounts, VAT, Net); Summary: per day.
 * Filters: Branches, Report Type*, Supplier, From*, To*.
 */
class DailyPurchaseDetailsReport extends Report
{
    protected static string $group = 'purchase';

    protected static string $slug = 'daily-purchase-details';

    protected static string $title = 'Daily Purchase Details';

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
        return PurchaseQuery::dailyReport($this->result(static::t('Purchases – Invoice Totals')), $filters, PurchaseQuery::LOCAL_TYPES);
    }
}
