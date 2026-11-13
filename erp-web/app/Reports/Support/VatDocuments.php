<?php

namespace App\Reports\Support;

use App\Enums\PurchaseDocumentType;
use App\Enums\SalesDocumentType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posted VAT-bearing documents with their taxable amount split by ZATCA VAT category.
 *
 * Categories per line: standard (tax % > 0), zero (0 %), exempt (VATEX-SA-29*, VATEX-SA-30),
 * export (VATEX-SA-32 / 33), health_edu (VATEX-SA-HEA / EDU, private healthcare / education to citizens),
 * import (foreign purchase invoices). Line taxable = net - tax, scaled by the document-level additional
 * discount so the categories add up to the document's net_total. VAT is the document's tax_total.
 * Returns carry sign -1.
 */
class VatDocuments
{
    public const CATEGORIES = ['standard', 'health_edu', 'zero', 'export', 'exempt', 'import'];

    public const SALES_TYPES = ['sales_invoice', 'sales_return', 'pos_invoice', 'pos_return', 'cash_sales_invoice', 'cash_sales_return', 'credit_note', 'debit_note'];

    /** Sales document types that reduce sales (returns and ZATCA credit notes). */
    public const SALES_REDUCING_TYPES = ['sales_return', 'pos_return', 'cash_sales_return', 'credit_note'];

    public const PURCHASE_TYPES = ['purchase_invoice', 'purchase_return', 'foreign_purchase_invoice', 'cash_purchase_invoice', 'cash_purchase_return'];

    /**
     * @return Collection<int, array{side: string, type: string, type_label: string, number: string, date: string, party: string, vat_no: ?string, sign: int, taxable: float, vat: float, total: float, standard: float, health_edu: float, zero: float, export: float, exempt: float, import: float}>
     */
    public static function get(string $from, string $to, ?int $branchId = null, ?string $side = null): Collection
    {
        $docs = collect();
        if ($side !== 'purchases') {
            $docs = $docs->merge(static::sales($from, $to, $branchId));
        }
        if ($side !== 'sales') {
            $docs = $docs->merge(static::purchases($from, $to, $branchId));
        }

        return $docs->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    protected static function sales(string $from, string $to, ?int $branchId): Collection
    {
        $docs = DB::table('sales_documents as d')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('cash_customers as cc', 'cc.id', '=', 'd.cash_customer_id')
            ->where('d.is_posted', true)
            ->whereIn('d.type', static::SALES_TYPES)
            ->where('d.date', '>=', Ledger::date($from))
            ->where('d.date', '<', Ledger::nextDay($to))
            ->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
            ->select('d.id', 'd.type', 'd.number', 'd.date', 'd.net_total', 'd.tax_total', 'd.grand_total',
                'c.name_ar as c_ar', 'c.name_en as c_en', 'c.vat_no as c_vat', 'cc.name_ar as cc_ar', 'cc.name_en as cc_en', 'cc.vat_no as cc_vat')
            ->orderBy('d.date')->orderBy('d.id')
            ->get();

        $lines = DB::table('sales_document_lines as l')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->whereIn('l.sales_document_id', $docs->pluck('id')->all() ?: [0])
            ->get(['l.sales_document_id as doc_id', 'l.net', 'l.tax', 'l.tax_percent', 'i.tax_exemption_reason as reason'])
            ->groupBy('doc_id');

        return $docs->map(function (object $d) use ($lines) {
            $label = SalesDocumentType::tryFrom($d->type)?->label() ?? ReportLang::get(str($d->type)->headline()->toString());

            return static::row('sales', $d, $label, in_array($d->type, static::SALES_REDUCING_TYPES, true) ? -1 : 1, $lines->get($d->id, collect()), false,
                Ledger::localName($d->c_ar ?? $d->cc_ar, $d->c_en ?? $d->cc_en), $d->c_vat ?? $d->cc_vat);
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    protected static function purchases(string $from, string $to, ?int $branchId): Collection
    {
        $docs = DB::table('purchase_documents as d')
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->leftJoin('cash_suppliers as cs', 'cs.id', '=', 'd.cash_supplier_id')
            ->where('d.is_posted', true)
            ->whereIn('d.type', static::PURCHASE_TYPES)
            ->where('d.date', '>=', Ledger::date($from))
            ->where('d.date', '<', Ledger::nextDay($to))
            ->when($branchId, fn ($q) => $q->where('d.branch_id', $branchId))
            ->select('d.id', 'd.type', 'd.number', 'd.date', 'd.net_total', 'd.tax_total', 'd.grand_total', 'd.exchange_rate',
                's.name_ar as s_ar', 's.name_en as s_en', 's.vat_no as s_vat', 'cs.name_ar as cs_ar', 'cs.name_en as cs_en', 'cs.vat_no as cs_vat')
            ->orderBy('d.date')->orderBy('d.id')
            ->get();

        $lines = DB::table('purchase_document_lines as l')
            ->leftJoin('items as i', 'i.id', '=', 'l.item_id')
            ->whereIn('l.purchase_document_id', $docs->pluck('id')->all() ?: [0])
            ->get(['l.purchase_document_id as doc_id', 'l.net', 'l.tax', 'l.tax_percent', 'i.tax_exemption_reason as reason'])
            ->groupBy('doc_id');

        return $docs->map(function (object $d) use ($lines) {
            $type = PurchaseDocumentType::from($d->type);

            return static::row('purchases', $d, $type->label(), $type->isReturn() ? -1 : 1, $lines->get($d->id, collect()),
                $type === PurchaseDocumentType::ForeignPurchaseInvoice,
                Ledger::localName($d->s_ar ?? $d->cs_ar, $d->s_en ?? $d->cs_en), $d->s_vat ?? $d->cs_vat);
        });
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array<string, mixed>
     */
    protected static function row(string $side, object $d, string $typeLabel, int $sign, Collection $lines, bool $isImport, string $party, ?string $vatNo): array
    {
        $amounts = array_fill_keys(static::CATEGORIES, 0.0);
        $rate = (float) (($d->exchange_rate ?? 1) ?: 1);
        $netTotal = round((float) $d->net_total * $rate, 2);
        $lineTaxable = $lines->sum(fn ($l) => (float) $l->net - (float) $l->tax);
        $factor = $lineTaxable > 0 ? (float) $d->net_total / $lineTaxable : 0.0;

        foreach ($lines as $line) {
            $category = $isImport ? 'import' : static::category((float) $line->tax_percent, $line->reason);
            $amounts[$category] += ((float) $line->net - (float) $line->tax) * $rate * $factor;
        }

        // Put any rounding difference on the largest category so categories add up to net_total.
        $amounts = array_map(fn ($v) => round($v, 2), $amounts);
        $diff = round($netTotal - array_sum($amounts), 2);
        if ($diff != 0.0) {
            $largest = array_search(max($amounts), $amounts, true);
            $amounts[$largest === false ? ($isImport ? 'import' : 'standard') : $largest] += $diff;
        }

        $row = [
            'side' => $side,
            'type' => $d->type,
            'type_label' => $typeLabel,
            'number' => (string) $d->number,
            'date' => (string) $d->date,
            'party' => $party,
            'vat_no' => $vatNo,
            'sign' => $sign,
            'taxable' => round($sign * $netTotal, 2),
            'vat' => round($sign * (float) $d->tax_total * $rate, 2),
            'total' => round($sign * (float) $d->grand_total * $rate, 2),
        ];
        foreach ($amounts as $category => $value) {
            $row[$category] = round($sign * $value, 2);
        }

        return $row;
    }

    public static function category(float $taxPercent, ?string $reason): string
    {
        if ($taxPercent > 0) {
            return 'standard';
        }
        $reason = strtoupper((string) $reason);

        return match (true) {
            str_starts_with($reason, 'VATEX-SA-29'), str_starts_with($reason, 'VATEX-SA-30') => 'exempt',
            in_array($reason, ['VATEX-SA-32', 'VATEX-SA-33'], true) => 'export',
            in_array($reason, ['VATEX-SA-HEA', 'VATEX-SA-EDU'], true) => 'health_edu',
            default => 'zero',
        };
    }
}
