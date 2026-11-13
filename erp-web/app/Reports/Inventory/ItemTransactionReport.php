<?php

namespace App\Reports\Inventory;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\StockQuery;
use App\Reports\Support\TradeFields;

/**
 * Item Transactions (حركة الأصناف): stock movements summarised by document / movement type — number of documents,
 * quantity in / out and cost value in / out (a movement type always comes from one source type, so documents = distinct source ids). Summary: one row per movement type; Detail: per item, one row per type.
 * Filters: Report Type*, Branches, Warehouse, Item, Item Category, From*, To*.
 */
class ItemTransactionReport extends Report
{
    protected static string $group = 'inventory';

    protected static string $slug = 'item-transaction';

    protected static string $title = 'Item Transactions';

    public function filters(): array
    {
        return [
            ReportFields::reportType()->required(),
            TradeFields::branches(),
            TradeFields::warehouse(),
            TradeFields::item(),
            TradeFields::category(),
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
        $detail = ($filters['report_type'] ?? 'summary') === 'detail';

        $query = StockQuery::movements($filters)
            ->where('m.date', '>=', Ledger::date($filters['from']))
            ->where('m.date', '<', Ledger::nextDay($filters['to']))
            ->select('m.movement_type')
            ->selectRaw('COUNT(DISTINCT m.source_id) as documents')
            ->selectRaw('SUM(m.qty_in) as qty_in, SUM(m.qty_out) as qty_out')
            ->selectRaw('SUM(m.qty_in * m.unit_cost) as value_in, SUM(m.qty_out * m.unit_cost) as value_out')
            ->groupBy('m.movement_type')
            ->orderBy('m.movement_type');
        if ($detail) {
            $query->addSelect(['i.id as item_id', 'i.code', 'i.name_ar', 'i.name_en'])->groupBy('i.id', 'i.code', 'i.name_ar', 'i.name_en')->reorder('i.code')->orderBy('m.movement_type');
        }
        $rows = $query->get();

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->columns([
                ReportColumn::text('type', 'Movement Type'),
                ReportColumn::number('documents', 'Documents'),
                ReportColumn::number('qty_in', 'Quantity In', 3),
                ReportColumn::number('qty_out', 'Quantity Out', 3),
                ReportColumn::number('net_qty', 'Net Quantity', 3),
                ReportColumn::money('value_in', 'Value In'),
                ReportColumn::money('value_out', 'Value Out'),
            ]);

        $keys = ['qty_in', 'qty_out', 'net_qty', 'value_in', 'value_out'];
        foreach ($detail ? $rows->groupBy('item_id') : ['all' => $rows] as $group) {
            if ($detail) {
                $first = $group->first();
                $result->addGroup($first->code.' - '.Ledger::localName($first->name_ar, $first->name_en));
            }
            $cells = [];
            foreach ($group as $row) {
                $cells[] = $cell = [
                    'type' => StockQuery::typeLabel((string) $row->movement_type),
                    'documents' => (int) $row->documents,
                    'qty_in' => round((float) $row->qty_in, 3),
                    'qty_out' => round((float) $row->qty_out, 3),
                    'net_qty' => round((float) $row->qty_in - (float) $row->qty_out, 3),
                    'value_in' => round((float) $row->value_in, 2),
                    'value_out' => round((float) $row->value_out, 2),
                ];
                $result->addRow($cell);
            }
            if ($detail) {
                $result->addSubtotal(['type' => static::t('Total')] + ReportTotals::sum($cells, $keys));
            }
        }

        return ReportTotals::footer($result, $keys, 'type');
    }
}
