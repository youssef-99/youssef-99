<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\SalesDocumentList;
use App\Reports\Support\TradeFields;

/**
 * Daily Quotations Details (تفاصيل عروض الأسعار اليومية): price quotations (posted or not), summary per day or every
 * quotation grouped by day with its status. Filters: Branches, Report Type*, Customer Group, Customer, From*, To*.
 */
class DailyQuotationsDetailsReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'daily-quotations-details';

    protected static string $title = 'Daily Quotations Details';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            ReportFields::reportType()->required(),
            ReportFields::customerGroup(),
            ReportFields::customer(),
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
        return SalesDocumentList::daily($this->result(), $filters, ['quotation'], posted: false, withStatus: true);
    }
}
