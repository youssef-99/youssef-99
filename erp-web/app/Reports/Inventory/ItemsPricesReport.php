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
 * Items Prices (أسعار الأصناف): purchase price, average cost, selling price, VAT, price including VAT and the profit
 * margin (selling price - cost basis, % of the selling price). Cost basis = average cost, or the purchase price for
 * items without stock cost yet.
 * Filters: Item Category, Item Group, Supplier, Item, Active Items Only.
 */
class ItemsPricesReport extends Report
{
    protected static string $group = 'inventory';

    protected static string $slug = 'items-prices';

    protected static string $title = 'Items Prices';

    public function filters(): array
    {
        return [
            TradeFields::category(),
            TradeFields::itemGroup(),
            ReportFields::supplier(),
            TradeFields::item(),
            TradeFields::checkbox('active_only', 'Active Items Only'),
        ];
    }

    public function defaults(): array
    {
        return ['active_only' => 1];
    }

    public function run(array $filters): ReportResult
    {
        $items = Item::query()
            ->with('unit')
            ->when(TradeFields::flag($filters, 'active_only'), fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['group_id'] ?? null, fn (Builder $q, $id) => $q->where('group_id', $id))
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('supplier_id', $id))
            ->when($filters['item_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->orderBy('code')
            ->get();

        $result = $this->result()
            ->landscape()
            ->columns([
                ReportColumn::text('code', 'Item Code'),
                ReportColumn::text('barcode', 'Barcode'),
                ReportColumn::text('name', 'Item Name'),
                ReportColumn::text('unit', 'Unit'),
                ReportColumn::number('purchase_price', 'Purchase Price', 4),
                ReportColumn::number('average_cost', 'Average Cost', 4),
                ReportColumn::number('sale_price', 'Selling Price', 4),
                ReportColumn::number('vat_rate', 'VAT %', 2),
                ReportColumn::money('vat', 'VAT'),
                ReportColumn::money('price_incl_vat', 'Price incl. VAT'),
                ReportColumn::money('margin', 'Profit Margin'),
                ReportColumn::number('margin_percent', 'Margin %', 2),
            ]);

        foreach ($items as $item) {
            $sale = (float) $item->sale_price;
            $cost = (float) $item->average_cost ?: (float) $item->purchase_price;
            $margin = $sale - $cost;
            $result->addRow([
                'code' => $item->code,
                'barcode' => $item->barcode,
                'name' => $item->name,
                'unit' => $item->unit?->name,
                'purchase_price' => round((float) $item->purchase_price, 4),
                'average_cost' => round((float) $item->average_cost, 4),
                'sale_price' => round($sale, 4),
                'vat_rate' => (float) $item->vat_rate,
                'vat' => round($sale * (float) $item->vat_rate / 100, 2),
                'price_incl_vat' => $item->sale_price_incl_tax,
                'margin' => round($margin, 2),
                'margin_percent' => $sale > 0 ? round($margin / $sale * 100, 2) : 0.0,
            ]);
        }

        return $result;
    }
}
