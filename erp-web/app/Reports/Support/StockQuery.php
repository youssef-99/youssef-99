<?php

namespace App\Reports\Support;

use App\Enums\PurchaseDocumentType;
use App\Enums\SalesDocumentType;
use App\Enums\StockDocumentType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Queries over the stock ledger (`stock_movements` m joined to `items` i) for the inventory reports.
 * Balance = SUM(qty_in - qty_out). Movement types are the posting document types (sales_invoice, pos_return,
 * purchase_invoice, ...) plus adjust_in / adjust_out / transfer_in / transfer_out / stocktake / opening.
 *
 * Recognised filters: branch_ids / branch_id, warehouse_id, item_id, item_ids, category_id, group_id, supplier_id,
 * manufacturer_id.
 */
class StockQuery
{
    /** @param  array<string, mixed>  $filters */
    public static function movements(array $filters): Builder
    {
        return DB::table('stock_movements as m')
            ->join('items as i', 'i.id', '=', 'm.item_id')
            ->when(TradeFields::branchIds($filters), fn (Builder $q, array $ids) => $q->whereIn('m.branch_id', $ids))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, $id) => $q->where('m.warehouse_id', $id))
            ->when($filters['item_id'] ?? null, fn (Builder $q, $id) => $q->where('m.item_id', $id))
            ->when(array_filter((array) ($filters['item_ids'] ?? [])), fn (Builder $q, array $ids) => $q->whereIn('m.item_id', $ids))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('i.category_id', $id))
            ->when($filters['group_id'] ?? null, fn (Builder $q, $id) => $q->where('i.group_id', $id))
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('i.supplier_id', $id))
            ->when($filters['manufacturer_id'] ?? null, fn (Builder $q, $id) => $q->where('i.manufacturer_id', $id));
    }

    /** Label of a movement type (نوع الحركة). */
    public static function typeLabel(string $type): string
    {
        $fixed = [
            'adjust_in' => 'Stock Increase Adjustment',
            'adjust_out' => 'Stock Decrease Adjustment',
            'transfer_in' => 'Inbound Transfer',
            'transfer_out' => 'Outbound Transfer',
            'stocktake' => 'Stocktake',
            'opening' => 'Opening Balance',
            'sale' => 'Sales Invoice',
            'purchase' => 'Purchase Invoice',
            'credit_note' => 'Credit Note',
            'debit_note' => 'Debit Note',
        ];
        if (isset($fixed[$type])) {
            return ReportLang::get($fixed[$type]);
        }

        return SalesDocumentType::tryFrom($type)?->label()
            ?? PurchaseDocumentType::tryFrom($type)?->label()
            ?? StockDocumentType::tryFrom($type)?->label()
            ?? ReportLang::get(str($type)->headline()->toString());
    }

    /**
     * Document numbers of movement sources keyed by "type:id".
     *
     * @param  iterable<int, object>  $movements  rows with source_type / source_id
     * @return array<string, string>
     */
    public static function sourceNumbers(iterable $movements): array
    {
        $ids = [];
        foreach ($movements as $m) {
            if ($m->source_type && $m->source_id) {
                $ids[$m->source_type][] = $m->source_id;
            }
        }
        $tables = ['sales_document' => 'sales_documents', 'purchase_document' => 'purchase_documents', 'stock_document' => 'stock_documents'];
        $out = [];
        foreach ($ids as $type => $list) {
            if (! isset($tables[$type])) {
                continue;
            }
            foreach (array_chunk(array_unique($list), 500) as $chunk) {
                foreach (DB::table($tables[$type])->whereIn('id', $chunk)->pluck('number', 'id') as $id => $number) {
                    $out["{$type}:{$id}"] = (string) $number;
                }
            }
        }

        return $out;
    }
}
