<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\SalesDocumentList;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;

/**
 * Daily Sales Details (تفاصيل المبيعات اليومية): posted credit, POS and cash sales invoices, returns and ZATCA notes.
 * Summary: one row per day; Detail: every document grouped by day with daily subtotals. Returns subtract.
 * Filters: Branches, Report Type*, Customer Group, Customer, From*, To*.
 */
class DailySalesDetailsReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'daily-sales-details';

    protected static string $title = 'Daily Sales Details';

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
        return ['report_type' => 'summary', 'from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        return SalesDocumentList::daily($this->result(), $filters, SalesQuery::salesTypes(), true);
    }
}
