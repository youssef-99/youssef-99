<?php

namespace App\Services;

use App\Enums\PurchaseDocumentType;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\StockMovement;
use App\Support\SafeSpreadsheetRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Bulk item tools (إجراءات على الأصناف). Every method takes a query of the items to act on.
 */
class ItemActionsService
{
    /** Purchase types whose lines count as a "last purchase". */
    public const PURCHASE_TYPES = [
        PurchaseDocumentType::PurchaseInvoice,
        PurchaseDocumentType::ForeignPurchaseInvoice,
        PurchaseDocumentType::CashPurchaseInvoice,
    ];

    /**
     * Narrow an item query by the common scope fields (category incl. sub-categories, agent, supplier, group).
     *
     * @param  array{category_id?: int|string|null, agent_id?: int|string|null, supplier_id?: int|string|null, group_id?: int|string|null}  $scope
     */
    public static function scope(array $scope, ?Builder $query = null): Builder
    {
        $query ??= Item::query();

        return $query
            ->when($scope['category_id'] ?? null, fn (Builder $q, $id) => $q->whereIn('category_id', static::categoryWithChildren((int) $id)))
            ->when($scope['agent_id'] ?? null, fn (Builder $q, $id) => $q->where('agent_id', $id))
            ->when($scope['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('supplier_id', $id))
            ->when($scope['group_id'] ?? null, fn (Builder $q, $id) => $q->where('group_id', $id));
    }

    /** @return list<int> */
    public static function categoryWithChildren(int $categoryId): array
    {
        $ids = [$categoryId];
        $frontier = [$categoryId];
        while ($frontier !== []) {
            $frontier = DB::table('item_categories')->whereIn('parent_id', $frontier)->whereNotIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /**
     * Update Purchase Price (تحديث سعر الشراء) from each item's last posted purchase line.
     *
     * @return int items updated
     */
    public static function updatePurchasePriceFromLastPurchase(Builder $items): int
    {
        $types = array_map(fn (PurchaseDocumentType $t) => $t->value, self::PURCHASE_TYPES);
        $updated = 0;

        $items->clone()->select(['id', 'purchase_price'])->chunkById(500, function ($chunk) use ($types, &$updated) {
            foreach ($chunk as $item) {
                $price = DB::table('purchase_document_lines as l')
                    ->join('purchase_documents as d', 'd.id', '=', 'l.purchase_document_id')
                    ->where('l.item_id', $item->id)
                    ->where('d.is_posted', true)
                    ->whereIn('d.type', $types)
                    ->where('l.unit_price', '>', 0)
                    ->orderByDesc('d.date')
                    ->orderByDesc('l.id')
                    ->value('l.unit_price');

                if ($price !== null && abs((float) $price - (float) $item->purchase_price) > 0.00001) {
                    Item::query()->whereKey($item->id)->update(['purchase_price' => round((float) $price, 4)]);
                    $updated++;
                }
            }
        });

        return $updated;
    }

    /**
     * Update Selling Price by % (تعديل سعر البيع بنسبة). Negative percentages lower the price.
     * Batch selling prices (when set) are changed by the same percentage.
     *
     * @return int items updated
     */
    public static function updateSalePriceByPercent(Builder $items, float $percent, bool $includeBatches = true): int
    {
        $factor = 1 + $percent / 100;
        $updated = 0;

        DB::transaction(function () use ($items, $factor, $includeBatches, &$updated) {
            $items->clone()->select(['id', 'sale_price'])->chunkById(500, function ($chunk) use ($factor, $includeBatches, &$updated) {
                foreach ($chunk as $item) {
                    Item::query()->whereKey($item->id)->update(['sale_price' => round(max(0, (float) $item->sale_price * $factor), 2)]);
                    if ($includeBatches) {
                        ItemBatch::query()->where('item_id', $item->id)->where('sale_price', '>', 0)
                            ->get(['id', 'sale_price'])
                            ->each(fn (ItemBatch $batch) => ItemBatch::query()->whereKey($batch->id)->update(['sale_price' => round((float) $batch->sale_price * $factor, 2)]));
                    }
                    $updated++;
                }
            });
        });

        return $updated;
    }

    /** Activate / deactivate items. @return int items changed */
    public static function setActive(Builder $items, bool $active): int
    {
        return $items->clone()->where('is_active', '!=', $active)->update(['is_active' => $active]);
    }

    /**
     * Send Items to e-POS (إرسال الأصناف لنقاط البيع): there is no live e-POS API, so the items are
     * exported to a CSV / XLSX file the e-POS imports, and flagged as sent.
     *
     * @return array{path: string, count: int}
     */
    public static function exportToEpos(Builder $items, string $format = 'xlsx'): array
    {
        $format = $format === 'csv' ? 'csv' : 'xlsx';
        $directory = storage_path('app/private/exports');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.'/epos-items-'.now()->format('Ymd-His').'-'.substr(md5((string) microtime(true)), 0, 6).'.'.$format;

        $writer = $format === 'csv' ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(SafeSpreadsheetRow::fromValues(['code', 'barcode', 'gtin', 'name_ar', 'name_en', 'unit', 'sale_price', 'sale_price_incl_vat', 'vat_rate', 'tax_exemption_reason', 'active']));

        $ids = [];
        $items->clone()->with('unit')->orderBy('code')->chunkById(500, function ($chunk) use ($writer, &$ids) {
            foreach ($chunk as $item) {
                $writer->addRow(SafeSpreadsheetRow::fromValues([
                    (string) $item->code,
                    (string) $item->barcode,
                    (string) $item->gtin,
                    (string) $item->name_ar,
                    (string) $item->name_en,
                    (string) ($item->unit?->code ?? ''),
                    round((float) $item->sale_price, 2),
                    $item->sale_price_incl_tax,
                    round((float) $item->vat_rate, 2),
                    (string) $item->tax_exemption_reason,
                    $item->is_active ? 1 : 0,
                ]));
                $ids[] = $item->id;
            }
        });
        $writer->close();

        foreach (array_chunk($ids, 500) as $chunk) {
            Item::query()->whereKey($chunk)->update(['sent_to_epos' => true]);
        }

        return ['path' => $path, 'count' => count($ids)];
    }

    /**
     * Recalculate batch balances (إعادة احتساب أرصدة التشغيلات): rebuilds each item's moving average
     * cost from the ledger, refreshes every batch's last purchase price from its last costed receipt,
     * and reports the batches whose balance is negative.
     *
     * @return array{items: int, batches: int, negative_batches: int}
     */
    public static function recalculateBatchBalances(Builder $items): array
    {
        $result = ['items' => 0, 'batches' => 0, 'negative_batches' => 0];

        DB::transaction(function () use ($items, &$result) {
            $items->clone()->select(['id'])->chunkById(200, function ($chunk) use (&$result) {
                foreach ($chunk as $item) {
                    Item::query()->whereKey($item->id)->update(['average_cost' => StockService::replayAverageCost($item->id)]);
                    $result['items']++;

                    foreach (ItemBatch::query()->where('item_id', $item->id)->pluck('id') as $batchId) {
                        $lastCost = StockMovement::query()
                            ->where('item_batch_id', $batchId)
                            ->where('qty_in', '>', 0)
                            ->where('unit_cost', '>', 0)
                            ->whereIn('movement_type', ['purchase_invoice', 'foreign_purchase_invoice', 'cash_purchase_invoice', 'purchase', 'opening', 'adjust_in'])
                            ->orderByDesc('date')
                            ->orderByDesc('id')
                            ->value('unit_cost');
                        if ($lastCost !== null) {
                            ItemBatch::query()->whereKey($batchId)->update(['last_purchase_price' => round((float) $lastCost, 4)]);
                        }
                        if (StockService::balance($item->id, null, $batchId) < 0) {
                            $result['negative_batches']++;
                        }
                        $result['batches']++;
                    }
                }
            });
        });

        return $result;
    }
}
