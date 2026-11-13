<?php

namespace App\Reports\Support;

use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Document-level sales lists shared by daily sales details, daily quotations, customer and user sales transactions.
 * Amounts per document: Total (gross), Discounts (items + additional), Net (excl. VAT), VAT, Grand Total;
 * returns and credit notes are negative.
 */
class SalesDocumentList
{
    /**
     * Documents of the filters, oldest first, with party, user and signed amounts.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $types
     * @return Collection<int, object>
     */
    public static function documents(array $filters, array $types, bool $posted = true): Collection
    {
        $sign = SalesQuery::sign();
        $query = SalesQuery::documents($filters, $types, $posted)
            ->leftJoin('users as us', 'us.id', '=', 'd.created_by')
            ->leftJoin('salesmen as sm', 'sm.id', '=', 'd.salesman_id')
            ->select(['d.id', 'd.type', 'd.number', 'd.date', 'd.customer_id', 'd.created_by', 'd.payment_method', 'd.status', 'd.branch_id',
                'us.name as user_name', 'sm.name_ar as sm_ar', 'sm.name_en as sm_en'])
            ->selectRaw("({$sign}) * d.gross_total as gross")
            ->selectRaw("({$sign}) * (d.items_discount + d.additional_discount) as discount")
            ->selectRaw("({$sign}) * d.net_total as net")
            ->selectRaw("({$sign}) * d.tax_total as vat")
            ->selectRaw("({$sign}) * d.grand_total as total")
            ->selectRaw("({$sign}) * d.cost_total as cost")
            ->orderBy('d.date')->orderBy('d.id');

        return SalesQuery::withParty($query)->get()->map(function (object $d) {
            foreach (['gross', 'discount', 'net', 'vat', 'total', 'cost'] as $key) {
                $d->{$key} = round((float) $d->{$key}, 2);
            }
            $d->party = SalesQuery::partyName($d);
            $d->day = Carbon::parse($d->date)->toDateString();

            return $d;
        });
    }

    /** @return array<int, ReportColumn> */
    public static function amountColumns(): array
    {
        return [
            ReportColumn::money('gross', 'Total'),
            ReportColumn::money('discount', 'Discounts'),
            ReportColumn::money('net', 'Net'),
            ReportColumn::money('vat', 'VAT'),
            ReportColumn::money('total', 'Grand Total'),
        ];
    }

    /** @return array<string, mixed> */
    public static function documentCells(object $d): array
    {
        return [
            'date' => $d->date,
            'type' => SalesQuery::typeLabel((string) $d->type),
            'number' => (string) $d->number,
            'party' => $d->party,
            'user' => $d->user_name,
            'status' => ReportLang::get(str((string) $d->status)->headline()->toString()),
            'payment' => __(str((string) $d->payment_method)->headline()->toString()),
            'gross' => $d->gross,
            'discount' => $d->discount,
            'net' => $d->net,
            'vat' => $d->vat,
            'total' => $d->total,
        ];
    }

    /**
     * Daily report: summary (row per day) or detail (documents grouped by day with subtotals).
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $types
     */
    public static function daily(ReportResult $result, array $filters, array $types, bool $posted, bool $withStatus = false): ReportResult
    {
        $documents = static::documents($filters, $types, $posted);
        $detail = ($filters['report_type'] ?? 'summary') === 'detail';
        $amountKeys = ['gross', 'discount', 'net', 'vat', 'total'];

        $result->period($filters['from'] ?? null, $filters['to'] ?? null)
            ->info('Branches', TradeFields::branchNames($filters));

        if (! $detail) {
            $result->columns([
                ReportColumn::date('date', 'Date'),
                ReportColumn::number('documents', 'Documents'),
                ...static::amountColumns(),
            ]);
            foreach ($documents->groupBy('day') as $day => $docs) {
                $result->addRow(['date' => $day, 'documents' => $docs->count()] + static::sums($docs, $amountKeys));
            }

            return ReportTotals::footer($result, ['documents', ...$amountKeys], 'date');
        }

        $result->landscape()->columns([
            ReportColumn::date('date', 'Date'),
            ReportColumn::text('type', 'Document Type'),
            ReportColumn::text('number', 'Document No.'),
            ReportColumn::text('party', 'Customer'),
            $withStatus ? ReportColumn::text('status', 'Status') : ReportColumn::text('payment', 'Payment Method'),
            ...static::amountColumns(),
        ]);
        foreach ($documents->groupBy('day') as $day => $docs) {
            $result->addGroup(ReportColumn::formatDate($day));
            foreach ($docs as $d) {
                $result->addRow(static::documentCells($d));
            }
            $result->addSubtotal(['party' => ReportLang::get('Day Total')] + static::sums($docs, $amountKeys));
        }

        return ReportTotals::footer($result, $amountKeys, 'party');
    }

    /**
     * @param  Collection<int, object>  $docs
     * @param  array<int, string>  $keys
     * @return array<string, float>
     */
    public static function sums(Collection $docs, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = round((float) $docs->sum($key), 2);
        }

        return $out;
    }
}
