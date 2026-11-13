<?php

namespace App\Reports\Purchases;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\PurchaseQuery;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\TradeFields;
use Illuminate\Database\Query\Builder;

/**
 * Item Purchase Details (تفاصيل مشتريات صنف): purchase lines of posted purchase, foreign and cash purchase invoices and
 * returns (negative), in SAR. Detail: every line grouped by item (date, document, supplier, batch, quantity, bonus,
 * unit price, discount, VAT, net incl. VAT); Summary: one row per item (quantity, bonus, value, average unit cost).
 * Filters: Branches, Report Type*, Item, Item Category, Supplier, From*, To*.
 */
class ItemPurchaseDetailsReport extends Report
{
    protected static string $group = 'purchase';

    protected static string $slug = 'item-purchase-details';

    protected static string $title = 'Item Purchase Details';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            ReportFields::reportType()->required(),
            TradeFields::item(),
            TradeFields::category(),
            ReportFields::supplier(),
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
        $sign = PurchaseQuery::sign();
        $rate = 'CASE WHEN d.exchange_rate > 0 THEN d.exchange_rate ELSE 1 END';
        $lines = PurchaseQuery::documents($filters, PurchaseQuery::POSTABLE_TYPES)
            ->join('purchase_document_lines as l', 'l.purchase_document_id', '=', 'd.id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->when($filters['item_id'] ?? null, fn (Builder $q, $id) => $q->where('l.item_id', $id))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('i.category_id', $id));

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape();

        if (($filters['report_type'] ?? 'summary') === 'summary') {
            $rows = $lines->groupBy('i.id', 'i.code', 'i.name_ar', 'i.name_en')
                ->select(['i.code', 'i.name_ar', 'i.name_en'])
                ->selectRaw('COUNT(DISTINCT d.id) as documents')
                ->selectRaw("SUM(({$sign}) * l.quantity) as quantity")
                ->selectRaw("SUM(({$sign}) * l.bonus_qty) as bonus")
                ->selectRaw("SUM(({$sign}) * (l.net - l.tax) * ({$rate})) as value")
                ->selectRaw("SUM(({$sign}) * l.tax * ({$rate})) as vat")
                ->selectRaw("SUM(({$sign}) * l.net * ({$rate})) as total")
                ->orderBy('i.code')
                ->get();

            $result->columns([
                ReportColumn::text('code', 'Item Code'),
                ReportColumn::text('name', 'Item Name'),
                ReportColumn::number('documents', 'Documents'),
                ReportColumn::number('quantity', 'Quantity', 3),
                ReportColumn::number('bonus', 'Bonus Qty', 3),
                ReportColumn::money('value', 'Value Excl. VAT'),
                ReportColumn::money('vat', 'VAT'),
                ReportColumn::money('total', 'Net'),
                ReportColumn::number('unit_cost', 'Average Unit Cost', 4),
            ]);
            foreach ($rows as $r) {
                $units = (float) $r->quantity + (float) $r->bonus;
                $result->addRow([
                    'code' => $r->code,
                    'name' => Ledger::localName($r->name_ar, $r->name_en),
                    'documents' => (int) $r->documents,
                    'quantity' => round((float) $r->quantity, 3),
                    'bonus' => round((float) $r->bonus, 3),
                    'value' => round((float) $r->value, 2),
                    'vat' => round((float) $r->vat, 2),
                    'total' => round((float) $r->total, 2),
                    'unit_cost' => $units != 0.0 ? round((float) $r->value / $units, 4) : 0.0,
                ]);
            }

            return ReportTotals::footer($result, ['quantity', 'bonus', 'value', 'vat', 'total'], 'name');
        }

        $rows = $lines
            ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
            ->leftJoin('cash_suppliers as cs', 'cs.id', '=', 'd.cash_supplier_id')
            ->select(['i.id as item_id', 'i.code', 'i.name_ar', 'i.name_en', 'd.date', 'd.type', 'd.number', 'l.batch_no',
                's.name_ar as s_ar', 's.name_en as s_en', 'cs.name_ar as cs_ar', 'cs.name_en as cs_en'])
            ->selectRaw("({$sign}) * l.quantity as quantity")
            ->selectRaw("({$sign}) * l.bonus_qty as bonus")
            ->selectRaw("l.unit_price * ({$rate}) as unit_price")
            ->selectRaw("({$sign}) * (l.discount + l.discount2) * ({$rate}) as discount")
            ->selectRaw("({$sign}) * l.tax * ({$rate}) as vat")
            ->selectRaw("({$sign}) * l.net * ({$rate}) as total")
            ->orderBy('i.code')->orderBy('d.date')->orderBy('d.id')->orderBy('l.id')
            ->get();

        $result->columns([
            ReportColumn::date('date', 'Date'),
            ReportColumn::text('type', 'Document Type'),
            ReportColumn::text('number', 'Document No.'),
            ReportColumn::text('supplier', 'Supplier'),
            ReportColumn::text('batch_no', 'Batch No.'),
            ReportColumn::number('quantity', 'Quantity', 3),
            ReportColumn::number('bonus', 'Bonus Qty', 3),
            ReportColumn::number('unit_price', 'Unit Price', 4),
            ReportColumn::money('discount', 'Discount'),
            ReportColumn::money('vat', 'VAT'),
            ReportColumn::money('total', 'Net'),
        ]);
        $keys = ['quantity', 'bonus', 'discount', 'vat', 'total'];
        foreach ($rows->groupBy('item_id') as $itemLines) {
            $first = $itemLines->first();
            $result->addGroup($first->code.' - '.Ledger::localName($first->name_ar, $first->name_en));
            $cells = [];
            foreach ($itemLines as $l) {
                $cells[] = $cell = [
                    'date' => $l->date,
                    'type' => PurchaseQuery::typeLabel((string) $l->type),
                    'number' => (string) $l->number,
                    'supplier' => Ledger::localName($l->s_ar ?? $l->cs_ar, $l->s_en ?? $l->cs_en),
                    'batch_no' => $l->batch_no,
                    'quantity' => round((float) $l->quantity, 3),
                    'bonus' => round((float) $l->bonus, 3),
                    'unit_price' => round((float) $l->unit_price, 4),
                    'discount' => round((float) $l->discount, 2),
                    'vat' => round((float) $l->vat, 2),
                    'total' => round((float) $l->total, 2),
                ];
                $result->addRow($cell);
            }
            $result->addSubtotal(['supplier' => static::t('Item Total')] + ReportTotals::sum($cells, $keys));
        }

        return ReportTotals::footer($result, $keys, 'supplier');
    }
}
