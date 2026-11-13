<?php

namespace App\Reports\Inventory;

use App\Models\Item;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\TradeFields;
use Illuminate\Database\Eloquent\Builder;

/**
 * Print Item Barcode (طباعة باركود الأصناف): printable labels with the item name, its barcode (drawn in the browser,
 * CODE128; the item code when it has no barcode) and the selling price including VAT.
 * Filters: Items (empty = every active item, up to 500), Item Category, Copies per Item, Show Price.
 * The export gives the label list (code, barcode, name, prices, copies).
 */
class PrintItemBarcodeReport extends Report
{
    public const MAX_ITEMS = 500;

    protected static string $group = 'inventory';

    protected static string $slug = 'print-item-barcode';

    protected static string $title = 'Print Item Barcode';

    public function filters(): array
    {
        return [
            TradeFields::items(),
            TradeFields::category(),
            ReportFields::integer('copies', 'Copies per Item')->maxValue(100),
            TradeFields::checkbox('show_price', 'Show Price'),
        ];
    }

    public function defaults(): array
    {
        return ['copies' => 1, 'show_price' => 1];
    }

    public function run(array $filters): ReportResult
    {
        $copies = max(1, min(100, (int) ($filters['copies'] ?? 1)));
        $itemIds = array_filter((array) ($filters['item_ids'] ?? []));

        $items = Item::query()
            ->when($itemIds, fn (Builder $q) => $q->whereKey($itemIds), fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->orderBy('code')
            ->limit(static::MAX_ITEMS)
            ->get();

        $result = $this->result()
            ->layout('reports.layouts.barcode-labels', ['show_price' => TradeFields::flag($filters, 'show_price')])
            ->columns([
                ReportColumn::text('code', 'Item Code'),
                ReportColumn::text('barcode', 'Barcode'),
                ReportColumn::text('name', 'Item Name'),
                ReportColumn::money('sale_price', 'Selling Price'),
                ReportColumn::number('vat_rate', 'VAT %', 2),
                ReportColumn::money('price_incl_vat', 'Price incl. VAT'),
                ReportColumn::number('copies', 'Copies'),
            ]);

        foreach ($items as $item) {
            $result->addRow([
                'code' => $item->code,
                'barcode' => (string) ($item->barcode ?: $item->code),
                'name' => $item->name,
                'sale_price' => round((float) $item->sale_price, 2),
                'vat_rate' => (float) $item->vat_rate,
                'price_incl_vat' => $item->sale_price_incl_tax,
                'copies' => $copies,
            ]);
        }

        return $result;
    }
}
