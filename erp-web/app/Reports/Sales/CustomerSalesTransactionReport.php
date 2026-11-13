<?php

namespace App\Reports\Sales;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesDocumentList;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;

/**
 * Customer Sales Transactions (حركة مبيعات العملاء): posted sales documents of credit customers.
 * Detail: documents grouped by customer with subtotals; Summary: one row per customer (invoices, returns, amounts).
 * Filters: Branches, Report Type*, Customer Group, Customer, From*, To*.
 */
class CustomerSalesTransactionReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'customer-sales-transaction';

    protected static string $title = 'Customer Sales Transactions';

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
        $documents = SalesDocumentList::documents($filters, SalesQuery::salesTypes())->whereNotNull('customer_id');
        $amountKeys = ['gross', 'discount', 'net', 'vat', 'total'];
        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape();

        if (($filters['report_type'] ?? 'detail') === 'summary') {
            $result->columns([
                ReportColumn::text('code', 'Customer Code'),
                ReportColumn::text('party', 'Customer'),
                ReportColumn::number('invoices', 'Invoices'),
                ReportColumn::number('returns_count', 'Returns Count'),
                ...SalesDocumentList::amountColumns(),
            ]);
            foreach ($documents->groupBy('customer_id')->sortBy(fn ($docs) => $docs->first()->customer_code) as $docs) {
                $returns = $docs->filter(fn ($d) => in_array($d->type, SalesQuery::RETURN_TYPES, true))->count();
                $result->addRow([
                    'code' => $docs->first()->customer_code,
                    'party' => $docs->first()->party,
                    'invoices' => $docs->count() - $returns,
                    'returns_count' => $returns,
                ] + SalesDocumentList::sums($docs, $amountKeys));
            }

            return ReportTotals::footer($result, ['invoices', 'returns_count', ...$amountKeys], 'party');
        }

        $result->columns([
            ReportColumn::date('date', 'Date'),
            ReportColumn::text('type', 'Document Type'),
            ReportColumn::text('number', 'Document No.'),
            ReportColumn::text('payment', 'Payment Method'),
            ...SalesDocumentList::amountColumns(),
        ]);
        foreach ($documents->groupBy('customer_id')->sortBy(fn ($docs) => $docs->first()->customer_code) as $docs) {
            $result->addGroup($docs->first()->customer_code.' - '.$docs->first()->party);
            foreach ($docs as $d) {
                $result->addRow(SalesDocumentList::documentCells($d));
            }
            $result->addSubtotal(['payment' => static::t('Customer Total')] + SalesDocumentList::sums($docs, $amountKeys));
        }

        return ReportTotals::footer($result, $amountKeys, 'payment');
    }
}
