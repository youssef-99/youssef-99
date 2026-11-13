<?php

namespace App\Reports\Support;

use App\Enums\PurchaseDocumentType;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Queries over purchase documents (`purchase_documents` d) for the purchase reports, plus the shared
 * "Purchases – Invoice Totals" (المشتريات - إجمالي الفواتير) layout: Invoice (No., Date, Type), Supplier (No., Name,
 * VAT No.), Value (Total, Discounts, VAT, Net). Returns are negative; amounts are converted to SAR with the
 * document exchange rate. Recognised filters: from, to, branch_ids / branch_id, supplier_id, supplier_group_id.
 */
class PurchaseQuery
{
    /** Local purchases: credit and cash purchase invoices and returns. */
    public const LOCAL_TYPES = ['purchase_invoice', 'purchase_return', 'cash_purchase_invoice', 'cash_purchase_return'];

    public const RETURN_TYPES = ['purchase_return', 'cash_purchase_return'];

    /** Every type that brings stock in or out (not orders). */
    public const POSTABLE_TYPES = ['purchase_invoice', 'purchase_return', 'foreign_purchase_invoice', 'cash_purchase_invoice', 'cash_purchase_return'];

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $types
     */
    public static function documents(array $filters, array $types, bool $posted = true): Builder
    {
        return DB::table('purchase_documents as d')
            ->whereIn('d.type', $types)
            ->when($posted, fn (Builder $q) => $q->where('d.is_posted', true))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('d.date', '>=', Ledger::date($from)))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('d.date', '<', Ledger::nextDay($to)))
            ->when(TradeFields::branchIds($filters), fn (Builder $q, array $ids) => $q->whereIn('d.branch_id', $ids))
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('d.supplier_id', $id))
            ->when($filters['supplier_group_id'] ?? null, fn (Builder $q, $id) => $q->whereIn('d.supplier_id', DB::table('suppliers')->where('supplier_group_id', $id)->select('id')));
    }

    /** SQL: -1 for returns, else 1. */
    public static function sign(): string
    {
        return 'CASE WHEN d.type IN ('.SalesQuery::in(static::RETURN_TYPES).') THEN -1 ELSE 1 END';
    }

    public static function typeLabel(string $type): string
    {
        return PurchaseDocumentType::tryFrom($type)?->label() ?? ReportLang::get(str($type)->headline()->toString());
    }

    /**
     * Documents with supplier and signed SAR amounts, oldest first.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $types
     * @return Collection<int, object>
     */
    public static function invoiceTotals(array $filters, array $types, bool $posted = true): Collection
    {
        $sign = static::sign();

        return static::documents($filters, $types, $posted)
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->leftJoin('cash_suppliers as cs', 'cs.id', '=', 'd.cash_supplier_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'd.currency_id')
            ->select(['d.id', 'd.type', 'd.number', 'd.date', 'd.status', 'd.supplier_invoice_no', 'd.exchange_rate',
                's.code as s_code', 's.name_ar as s_ar', 's.name_en as s_en', 's.vat_no as s_vat',
                'cs.code as cs_code', 'cs.name_ar as cs_ar', 'cs.name_en as cs_en', 'cs.vat_no as cs_vat', 'cur.code as currency'])
            ->selectRaw("({$sign}) * d.gross_total as gross")
            ->selectRaw("({$sign}) * (d.items_discount + d.additional_discount) as discount")
            ->selectRaw("({$sign}) * d.tax_total as vat")
            ->selectRaw("({$sign}) * d.grand_total as total")
            ->selectRaw("({$sign}) * (d.shipping_cost + d.purchase_expenses) as expenses")
            ->orderBy('d.date')->orderBy('d.id')
            ->get()
            ->map(function (object $d) {
                $rate = (float) $d->exchange_rate ?: 1.0;
                $d->foreign_total = round((float) $d->total, 2);
                foreach (['gross', 'discount', 'vat', 'total'] as $key) {
                    $d->{$key} = round((float) $d->{$key} * $rate, 2);
                }
                $d->expenses = round((float) $d->expenses, 2);
                $d->supplier_code = $d->s_code ?? $d->cs_code;
                $d->supplier_name = Ledger::localName($d->s_ar ?? $d->cs_ar, $d->s_en ?? $d->cs_en);
                $d->supplier_vat = $d->s_vat ?? $d->cs_vat;
                $d->day = Carbon::parse($d->date)->toDateString();

                return $d;
            });
    }

    /**
     * "Purchases – Invoice Totals" report: detail (one row per document, grouped by day) or summary (one row per day).
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $types
     * @param  array<int, ReportColumn>  $extraColumns  inserted after the supplier band (e.g. currency, status)
     * @param  (\Closure(object): array<string, mixed>)|null  $extraCells
     */
    public static function dailyReport(ReportResult $result, array $filters, array $types, bool $posted = true, array $extraColumns = [], ?\Closure $extraCells = null): ReportResult
    {
        $documents = static::invoiceTotals($filters, $types, $posted);
        $keys = ['gross', 'discount', 'vat', 'total'];
        $valueColumns = [
            ReportColumn::money('gross', 'Total', 'Value'),
            ReportColumn::money('discount', 'Discounts', 'Value'),
            ReportColumn::money('vat', 'VAT', 'Value'),
            ReportColumn::money('total', 'Net', 'Value'),
        ];

        $result->period($filters['from'] ?? null, $filters['to'] ?? null)
            ->info('Branches', TradeFields::branchNames($filters));

        if (($filters['report_type'] ?? 'detail') === 'summary') {
            $result->columns([ReportColumn::date('date', 'Date'), ReportColumn::number('documents', 'Documents'), ...$valueColumns]);
            foreach ($documents->groupBy('day') as $day => $docs) {
                $result->addRow(['date' => $day, 'documents' => $docs->count()] + SalesDocumentList::sums($docs, $keys));
            }

            return ReportTotals::footer($result, ['documents', ...$keys], 'date');
        }

        $result->landscape()->columns([
            ReportColumn::text('number', 'No.', 'Invoice'),
            ReportColumn::date('date', 'Date', 'Invoice'),
            ReportColumn::text('type', 'Type', 'Invoice'),
            ReportColumn::text('supplier_code', 'No.', 'Supplier'),
            ReportColumn::text('supplier_name', 'Name', 'Supplier'),
            ReportColumn::text('supplier_vat', 'VAT No.', 'Supplier'),
            ...$extraColumns,
            ...$valueColumns,
        ]);
        foreach ($documents as $d) {
            $result->addRow([
                'number' => (string) $d->number,
                'date' => $d->date,
                'type' => static::typeLabel((string) $d->type),
                'supplier_code' => $d->supplier_code,
                'supplier_name' => $d->supplier_name,
                'supplier_vat' => $d->supplier_vat,
                'gross' => $d->gross,
                'discount' => $d->discount,
                'vat' => $d->vat,
                'total' => $d->total,
            ] + ($extraCells ? $extraCells($d) : []));
        }

        return ReportTotals::footer($result, $keys, 'supplier_name');
    }
}
