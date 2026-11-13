<?php

namespace App\Reports\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated sales figures (computed in SQL) for the statistics / analysis reports.
 */
class SalesAggregates
{
    /**
     * Per item: sold / returned / free quantity, sales, returns (positive), net sales, VAT, cost, profit, documents.
     * Amounts exclude VAT unless $withTax.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    public static function byItem(array $filters, bool $withTax = false): Collection
    {
        $notes = SalesQuery::in(SalesQuery::NOTE_TYPES);
        $isReturn = SalesQuery::isReturn();
        $factor = SalesQuery::factor();
        $value = ($withTax ? 'l.net' : '(l.net - l.tax)')." * ({$factor})";

        return SalesQuery::lines($filters)
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->groupBy('i.id', 'i.code', 'i.name_ar', 'i.name_en', 'u.name_ar', 'u.name_en')
            ->select(['i.id as item_id', 'i.code', 'i.name_ar', 'i.name_en', 'u.name_ar as unit_ar', 'u.name_en as unit_en'])
            ->selectRaw("SUM(CASE WHEN d.type IN ({$notes}) OR {$isReturn} THEN 0 ELSE l.quantity END) as qty_sold")
            ->selectRaw("SUM(CASE WHEN d.type NOT IN ({$notes}) AND {$isReturn} THEN l.quantity ELSE 0 END) as qty_returned")
            ->selectRaw('SUM('.SalesQuery::lineFreeQty().') as free_qty')
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 0 ELSE {$value} END) as sales")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN {$value} ELSE 0 END) as returns")
            ->selectRaw('SUM('.SalesQuery::lineTax().') as vat')
            ->selectRaw('SUM('.SalesQuery::lineCost().') as cost')
            ->selectRaw('SUM('.SalesQuery::lineAmount(false).') as net_excl')
            ->selectRaw('COUNT(DISTINCT d.id) as documents')
            ->orderBy('i.code')
            ->get()
            ->map(function (object $r) {
                foreach (['qty_sold', 'qty_returned', 'free_qty'] as $key) {
                    $r->{$key} = round((float) $r->{$key}, 3);
                }
                foreach (['sales', 'returns', 'vat', 'cost', 'net_excl'] as $key) {
                    $r->{$key} = round((float) $r->{$key}, 2);
                }
                $r->net_qty = round($r->qty_sold - $r->qty_returned, 3);
                $r->net_sales = round($r->sales - $r->returns, 2);
                $r->profit = round($r->net_excl - $r->cost, 2);
                $r->name = Ledger::localName($r->name_ar, $r->name_en);
                $r->unit = Ledger::localName($r->unit_ar, $r->unit_en);

                return $r;
            });
    }

    /**
     * Document-level totals grouped by a sales_documents column (e.g. "d.customer_id", "d.created_by", "d.doctor_id").
     * Fields: invoices, returns_count, sales, returns (positive, grand total or net per $withTax), net_sales, net (excl. VAT, signed),
     * vat (signed), total (signed grand total), cost (signed), profit (net - cost), last_date.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>|null  $types
     * @return Collection<int|string, object>
     */
    public static function byColumn(array $filters, string $column, bool $withTax = false, ?array $types = null): Collection
    {
        $isReturn = SalesQuery::isReturn();
        $amount = $withTax ? 'd.grand_total' : 'd.net_total';

        return SalesQuery::documents($filters, $types)
            ->groupBy(DB::raw($column))
            ->selectRaw("{$column} as group_key")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 0 ELSE 1 END) as invoices")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 1 ELSE 0 END) as returns_count")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN 0 ELSE {$amount} END) as sales")
            ->selectRaw("SUM(CASE WHEN {$isReturn} THEN {$amount} ELSE 0 END) as returns")
            ->selectRaw('SUM('.SalesQuery::docAmount(false).') as net')
            ->selectRaw('SUM(('.SalesQuery::sign().') * d.tax_total) as vat')
            ->selectRaw('SUM('.SalesQuery::docAmount(true).') as total')
            ->selectRaw('SUM(('.SalesQuery::sign().') * d.cost_total) as cost')
            ->selectRaw('MAX(d.date) as last_date')
            ->get()
            ->map(function (object $r) {
                foreach (['sales', 'returns', 'net', 'vat', 'total', 'cost'] as $key) {
                    $r->{$key} = round((float) $r->{$key}, 2);
                }
                $r->invoices = (int) $r->invoices;
                $r->returns_count = (int) $r->returns_count;
                $r->net_sales = round($r->sales - $r->returns, 2);
                $r->profit = round($r->net - $r->cost, 2);
                $r->margin = SalesQuery::margin($r->profit, $r->net);

                return $r;
            })
            ->keyBy('group_key');
    }
}
