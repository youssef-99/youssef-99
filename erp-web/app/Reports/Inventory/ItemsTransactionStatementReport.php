<?php

namespace App\Reports\Inventory;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\StockQuery;
use App\Reports\Support\TradeFields;
use Illuminate\Support\Facades\DB;

/**
 * Items Transaction Statement (كشف حركة صنف): per item, the opening balance, every stock movement in the period
 * (date, movement type, document no., warehouse, batch, in, out, unit cost) with a running balance, and the closing balance.
 * Filters: Item (empty = every item that moved), Item Category, Branches, Warehouse, From*, To*.
 */
class ItemsTransactionStatementReport extends Report
{
    protected static string $group = 'inventory';

    protected static string $slug = 'items-transaction-statement';

    protected static string $title = 'Items Transaction Statement';

    public function filters(): array
    {
        return [
            TradeFields::item(),
            TradeFields::category(),
            TradeFields::branches(),
            TradeFields::warehouse(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $from = Ledger::date($filters['from']);

        $openings = StockQuery::movements($filters)
            ->where('m.date', '<', $from)
            ->groupBy('m.item_id')
            ->selectRaw('m.item_id, SUM(m.qty_in - m.qty_out) as balance')
            ->pluck('balance', 'item_id');

        $movements = StockQuery::movements($filters)
            ->where('m.date', '>=', $from)
            ->where('m.date', '<', Ledger::nextDay($filters['to']))
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('item_batches as b', 'b.id', '=', 'm.item_batch_id')
            ->orderBy('i.code')->orderBy('m.date')->orderBy('m.id')
            ->get(['m.id', 'm.item_id', 'm.date', 'm.movement_type', 'm.qty_in', 'm.qty_out', 'm.unit_cost', 'm.source_type', 'm.source_id',
                'w.name_ar as w_ar', 'w.name_en as w_en', 'b.batch_no']);
        $numbers = StockQuery::sourceNumbers($movements);

        $itemIds = $movements->pluck('item_id')->merge($openings->filter(fn ($v) => round((float) $v, 3) != 0.0)->keys())->unique()->all();
        $items = DB::table('items')->whereIn('id', $itemIds ?: [0])->orderBy('code')->get(['id', 'code', 'name_ar', 'name_en']);
        $byItem = $movements->groupBy('item_id');

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape()
            ->columns([
                ReportColumn::date('date', 'Date'),
                ReportColumn::text('type', 'Movement Type'),
                ReportColumn::text('number', 'Document No.'),
                ReportColumn::text('warehouse', 'Warehouse'),
                ReportColumn::text('batch_no', 'Batch No.'),
                ReportColumn::number('qty_in', 'Quantity In', 3),
                ReportColumn::number('qty_out', 'Quantity Out', 3),
                ReportColumn::number('balance', 'Balance', 3),
                ReportColumn::number('unit_cost', 'Unit Cost', 4),
            ]);

        $totalIn = $totalOut = 0.0;
        foreach ($items as $item) {
            $balance = round((float) ($openings[$item->id] ?? 0), 3);
            $result->addGroup($item->code.' - '.Ledger::localName($item->name_ar, $item->name_en));
            $result->addRow(['type' => static::t('Opening Balance'), 'balance' => $balance], 'opening');
            $in = $out = 0.0;
            foreach ($byItem->get($item->id, collect()) as $m) {
                $balance = round($balance + (float) $m->qty_in - (float) $m->qty_out, 3);
                $in += (float) $m->qty_in;
                $out += (float) $m->qty_out;
                $result->addRow([
                    'date' => $m->date,
                    'type' => StockQuery::typeLabel((string) $m->movement_type),
                    'number' => $numbers["{$m->source_type}:{$m->source_id}"] ?? null,
                    'warehouse' => Ledger::localName($m->w_ar, $m->w_en),
                    'batch_no' => $m->batch_no,
                    'qty_in' => round((float) $m->qty_in, 3),
                    'qty_out' => round((float) $m->qty_out, 3),
                    'balance' => $balance,
                    'unit_cost' => round((float) $m->unit_cost, 4),
                ]);
            }
            $result->addSubtotal(['type' => static::t('Closing Balance'), 'qty_in' => round($in, 3), 'qty_out' => round($out, 3), 'balance' => $balance]);
            $totalIn += $in;
            $totalOut += $out;
        }

        return $result->addTotal(['type' => static::t('Total'), 'qty_in' => round($totalIn, 3), 'qty_out' => round($totalOut, 3)]);
    }
}
