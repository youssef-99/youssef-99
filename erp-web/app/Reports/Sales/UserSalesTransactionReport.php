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
 * User Sales Transactions (حركة مبيعات المستخدمين): every posted sales document grouped by the user who created it,
 * with a subtotal per user. Filters: Branches, User, Sales Type (all / credit / POS / cash), From*, To*.
 */
class UserSalesTransactionReport extends Report
{
    public const KINDS = [
        'credit' => ['sales_invoice', 'sales_return', 'credit_note', 'debit_note'],
        'pos' => ['pos_invoice', 'pos_return'],
        'cash' => ['cash_sales_invoice', 'cash_sales_return'],
    ];

    protected static string $group = 'sales';

    protected static string $slug = 'user-sales-transaction';

    protected static string $title = 'User Sales Transactions';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            TradeFields::user(),
            TradeFields::choice('kind', 'Sales Type', ['all' => 'All', 'credit' => 'Credit Sales', 'pos' => 'POS Sales', 'cash' => 'Cash Sales']),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['kind' => 'all', 'from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $types = static::KINDS[$filters['kind'] ?? 'all'] ?? SalesQuery::salesTypes();
        $documents = SalesDocumentList::documents($filters, $types);
        $amountKeys = ['gross', 'discount', 'net', 'vat', 'total'];

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape()
            ->columns([
                ReportColumn::date('date', 'Date'),
                ReportColumn::text('type', 'Document Type'),
                ReportColumn::text('number', 'Document No.'),
                ReportColumn::text('party', 'Customer'),
                ReportColumn::text('payment', 'Payment Method'),
                ...SalesDocumentList::amountColumns(),
            ]);

        foreach ($documents->groupBy(fn ($d) => (int) $d->created_by)->sortBy(fn ($docs) => $docs->first()->user_name) as $docs) {
            $result->addGroup($docs->first()->user_name ?? '-');
            foreach ($docs as $d) {
                $result->addRow(SalesDocumentList::documentCells($d));
            }
            $result->addSubtotal(['payment' => static::t('User Total')] + SalesDocumentList::sums($docs, $amountKeys));
        }

        return ReportTotals::footer($result, $amountKeys, 'payment');
    }
}
