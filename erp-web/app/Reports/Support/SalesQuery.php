<?php

namespace App\Reports\Support;

use App\Enums\SalesDocumentType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Queries over sales documents (`sales_documents` d) and their lines (`sales_document_lines` l) for the sales reports.
 *
 * - Sales = posted credit, POS and cash sales invoices and ZATCA debit notes; returns and credit notes subtract
 *   (signed amounts). Types are plain strings so notes work whether or not the enum has those cases.
 * - Line amounts are scaled by the document's additional discount: factor = net_total / (gross_total - items_discount),
 *   so per-line values add up to the document's net_total (excl. VAT).
 * - Quantities ignore ZATCA notes (their lines carry value only).
 * - Cost = (quantity + free quantity) × unit cost stored on the line when posted.
 *
 * Recognised filters (applied when present): from, to, branch_ids / branch_id, customer_id, customer_group_id,
 * salesman_id, user_id (created by), doctor_id, points_customer_id.
 */
class SalesQuery
{
    public const INVOICE_TYPES = ['sales_invoice', 'pos_invoice', 'cash_sales_invoice', 'debit_note'];

    public const RETURN_TYPES = ['sales_return', 'pos_return', 'cash_sales_return', 'credit_note'];

    public const NOTE_TYPES = ['credit_note', 'debit_note'];

    public const POS_TYPES = ['pos_invoice', 'pos_return'];

    /** @return array<int, string> */
    public static function salesTypes(): array
    {
        return [...static::INVOICE_TYPES, ...static::RETURN_TYPES];
    }

    /**
     * Sales documents matching the filters.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>|null  $types  defaults to every sales type
     */
    public static function documents(array $filters, ?array $types = null, bool $posted = true): Builder
    {
        $query = DB::table('sales_documents as d')
            ->whereIn('d.type', $types ?? static::salesTypes())
            ->when($posted, fn (Builder $q) => $q->where('d.is_posted', true))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('d.date', '>=', Ledger::date($from)))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('d.date', '<', Ledger::nextDay($to)))
            ->when(TradeFields::branchIds($filters), fn (Builder $q, array $ids) => $q->whereIn('d.branch_id', $ids))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('d.customer_id', $id))
            ->when($filters['customer_group_id'] ?? null, fn (Builder $q, $id) => $q->whereIn('d.customer_id', DB::table('customers')->where('customer_group_id', $id)->select('id')))
            ->when($filters['salesman_id'] ?? null, fn (Builder $q, $id) => $q->where('d.salesman_id', $id))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('d.created_by', $id))
            ->when($filters['doctor_id'] ?? null, fn (Builder $q, $id) => $q->where('d.doctor_id', $id))
            ->when($filters['points_customer_id'] ?? null, fn (Builder $q, $id) => $q->where('d.points_customer_id', $id));

        return $query;
    }

    /**
     * Lines of the matching documents joined to their items (`i`). Adds item filters item_id, item_ids, category_id,
     * group_id and supplier_id (the item's supplier).
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>|null  $types
     */
    public static function lines(array $filters, ?array $types = null, bool $posted = true): Builder
    {
        return static::documents($filters, $types, $posted)
            ->join('sales_document_lines as l', 'l.sales_document_id', '=', 'd.id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->when($filters['item_id'] ?? null, fn (Builder $q, $id) => $q->where('l.item_id', $id))
            ->when(array_filter((array) ($filters['item_ids'] ?? [])), fn (Builder $q, array $ids) => $q->whereIn('l.item_id', $ids))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('i.category_id', $id))
            ->when($filters['group_id'] ?? null, fn (Builder $q, $id) => $q->where('i.group_id', $id))
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('i.supplier_id', $id));
    }

    /** @param  array<int, string>  $values */
    public static function in(array $values): string
    {
        return implode(',', array_map(fn (string $v) => "'".str_replace("'", "''", $v)."'", $values));
    }

    /** SQL: -1 for returns / credit notes, else 1. */
    public static function sign(): string
    {
        return 'CASE WHEN d.type IN ('.static::in(static::RETURN_TYPES).') THEN -1 ELSE 1 END';
    }

    /** SQL condition: the document reduces sales. */
    public static function isReturn(): string
    {
        return 'd.type IN ('.static::in(static::RETURN_TYPES).')';
    }

    /** SQL: document-level additional discount factor for line amounts. */
    public static function factor(): string
    {
        return 'CASE WHEN (d.gross_total - d.items_discount) > 0 THEN d.net_total / (d.gross_total - d.items_discount) ELSE 1 END';
    }

    /** SQL: signed line value excluding (default) or including VAT. */
    public static function lineAmount(bool $withTax = false): string
    {
        $base = $withTax ? 'l.net' : '(l.net - l.tax)';

        return '('.static::sign().') * '.$base.' * ('.static::factor().')';
    }

    /** SQL: signed line VAT. */
    public static function lineTax(): string
    {
        return '('.static::sign().') * l.tax * ('.static::factor().')';
    }

    /** SQL: signed sold quantity (ZATCA notes excluded). */
    public static function lineQty(): string
    {
        return 'CASE WHEN d.type IN ('.static::in(static::NOTE_TYPES).') THEN 0 ELSE ('.static::sign().') * l.quantity END';
    }

    /** SQL: signed free quantity. */
    public static function lineFreeQty(): string
    {
        return 'CASE WHEN d.type IN ('.static::in(static::NOTE_TYPES).') THEN 0 ELSE ('.static::sign().') * l.free_qty END';
    }

    /** SQL: signed line cost. */
    public static function lineCost(): string
    {
        return 'CASE WHEN d.type IN ('.static::in(static::NOTE_TYPES).') THEN 0 ELSE ('.static::sign().') * (l.quantity + l.free_qty) * l.unit_cost END';
    }

    /** SQL: signed document value excluding (net_total) or including VAT (grand_total). */
    public static function docAmount(bool $withTax = false): string
    {
        return '('.static::sign().') * '.($withTax ? 'd.grand_total' : 'd.net_total');
    }

    /** @param  array<string, mixed>  $filters */
    public static function withTax(array $filters): bool
    {
        return ($filters['tax_mode'] ?? 'without') === 'with';
    }

    /** Sales column label for the chosen tax mode. */
    public static function amountNote(array $filters): string
    {
        return ReportLang::get(static::withTax($filters) ? 'Including VAT' : 'Excluding VAT');
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'credit_note' => ReportLang::get('Credit Note'),
            'debit_note' => ReportLang::get('Debit Note'),
            default => SalesDocumentType::tryFrom($type)?->label() ?? ReportLang::get(str($type)->headline()->toString()),
        };
    }

    /**
     * Select party name columns (customer, cash customer, points customer) onto a documents query.
     */
    public static function withParty(Builder $query): Builder
    {
        return $query
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('cash_customers as cc', 'cc.id', '=', 'd.cash_customer_id')
            ->leftJoin('points_customers as pc', 'pc.id', '=', 'd.points_customer_id')
            ->addSelect(['c.code as customer_code', 'c.name_ar as c_ar', 'c.name_en as c_en', 'c.vat_no as customer_vat',
                'cc.name_ar as cc_ar', 'cc.name_en as cc_en', 'pc.name as pc_name']);
    }

    /** Party name of a row selected with {@see withParty()}. */
    public static function partyName(object $row): string
    {
        if (filled($row->c_ar ?? null) || filled($row->c_en ?? null)) {
            return Ledger::localName($row->c_ar, $row->c_en);
        }
        if (filled($row->cc_ar ?? null) || filled($row->cc_en ?? null)) {
            return Ledger::localName($row->cc_ar, $row->cc_en);
        }

        return filled($row->pc_name ?? null) ? (string) $row->pc_name : ReportLang::get('Cash Customer');
    }

    /** Profit margin % of sales. */
    public static function margin(float $profit, float $sales): float
    {
        return $sales != 0.0 ? round($profit / $sales * 100, 2) : 0.0;
    }
}
