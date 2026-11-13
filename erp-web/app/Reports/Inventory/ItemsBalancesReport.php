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
use Filament\Forms\Components\CheckboxList;
use Illuminate\Support\Facades\DB;

/**
 * Items Balances (أرصدة الأصناف): opening, quantity in / out in the period and closing balance from the stock ledger.
 * Filters: Branches, Filter By* (all items / with balance / with movement / negative balance), Item, Item Category,
 * Warehouse, From*, To*, Quantity greater than / less than (on the closing balance), Show By* (item, item + batch,
 * item + warehouse), Sort By* (code, name, balance), Other Options (hide zero balances, show cost value).
 */
class ItemsBalancesReport extends Report
{
    protected static string $group = 'inventory';

    protected static string $slug = 'items-balances';

    protected static string $title = 'Items Balances';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            TradeFields::choice('filter_by', 'Filter By', [
                'all' => 'All Items',
                'balance' => 'Items with Balance',
                'movement' => 'Items with Movement',
                'negative' => 'Negative Balance',
            ])->required(),
            TradeFields::item(),
            TradeFields::category(),
            TradeFields::warehouse(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
            TradeFields::number('qty_more', 'Quantity Greater Than'),
            TradeFields::number('qty_less', 'Quantity Less Than'),
            TradeFields::choice('show_by', 'Show By', ['item' => 'Item', 'batch' => 'Item and Batch', 'warehouse' => 'Item and Warehouse'])->required(),
            TradeFields::choice('sort_by', 'Sort By', ['code' => 'Item Code', 'name' => 'Item Name', 'balance' => 'Balance'])->required(),
            CheckboxList::make('options')
                ->label(static::t('Other Options'))
                ->options(['hide_zero' => static::t('Hide Zero Balances'), 'show_value' => static::t('Show Cost Value')])
                ->columns(2),
        ];
    }

    public function defaults(): array
    {
        return ['filter_by' => 'all', 'show_by' => 'item', 'sort_by' => 'code', 'from' => ReportFields::fiscalYearStart(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $showBy = $filters['show_by'] ?? 'item';
        $options = (array) ($filters['options'] ?? []);
        $showValue = in_array('show_value', $options, true);
        $hideZero = in_array('hide_zero', $options, true);
        $from = Ledger::date($filters['from']);
        $toNext = Ledger::nextDay($filters['to']);

        $query = StockQuery::movements($filters)
            ->where('m.date', '<', $toNext)
            ->select('m.item_id')
            ->selectRaw('SUM(CASE WHEN m.date < ? THEN m.qty_in - m.qty_out ELSE 0 END) as opening', [$from])
            ->selectRaw('SUM(CASE WHEN m.date >= ? THEN m.qty_in ELSE 0 END) as qty_in', [$from])
            ->selectRaw('SUM(CASE WHEN m.date >= ? THEN m.qty_out ELSE 0 END) as qty_out', [$from])
            ->groupBy('m.item_id');
        if ($showBy === 'batch') {
            $query->addSelect('m.item_batch_id')->groupBy('m.item_batch_id');
        } elseif ($showBy === 'warehouse') {
            $query->addSelect('m.warehouse_id')->groupBy('m.warehouse_id');
        }
        $balances = $query->get();

        $items = DB::table('items as i')
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->when($filters['item_id'] ?? null, fn ($q, $id) => $q->where('i.id', $id))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('i.category_id', $id))
            ->where('i.item_type', '!=', 'service')
            ->get(['i.id', 'i.code', 'i.name_ar', 'i.name_en', 'i.average_cost', 'u.name_ar as unit_ar', 'u.name_en as unit_en'])
            ->keyBy('id');
        $batches = $showBy === 'batch'
            ? DB::table('item_batches')->whereIn('id', $balances->pluck('item_batch_id')->filter()->unique()->all() ?: [0])->get(['id', 'batch_no', 'expiry_date'])->keyBy('id')
            : collect();
        $warehouses = $showBy === 'warehouse'
            ? DB::table('warehouses')->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id')
            : collect();

        $rows = [];
        foreach ($balances as $b) {
            $item = $items->get($b->item_id);
            if (! $item) {
                continue;
            }
            $rows[] = $this->row($item, $b, $batches, $warehouses);
        }

        // Items without any movement (filter "all items", item view only).
        if (($filters['filter_by'] ?? 'all') === 'all' && $showBy === 'item' && ! $hideZero && ! array_filter([$filters['warehouse_id'] ?? null, TradeFields::branchIds($filters)])) {
            $moved = $balances->pluck('item_id')->flip();
            foreach ($items as $item) {
                if (! $moved->has($item->id)) {
                    $rows[] = $this->row($item, (object) ['opening' => 0, 'qty_in' => 0, 'qty_out' => 0], $batches, $warehouses);
                }
            }
        }

        $filterBy = $filters['filter_by'] ?? 'all';
        $more = is_numeric($filters['qty_more'] ?? null) ? (float) $filters['qty_more'] : null;
        $less = is_numeric($filters['qty_less'] ?? null) ? (float) $filters['qty_less'] : null;
        $rows = array_values(array_filter($rows, function (array $r) use ($filterBy, $more, $less, $hideZero) {
            $closing = round($r['closing'], 3);

            return match ($filterBy) {
                'balance' => $closing != 0.0,
                'movement' => $r['qty_in'] != 0.0 || $r['qty_out'] != 0.0,
                'negative' => $closing < 0,
                default => true,
            } && ($more === null || $closing > $more)
              && ($less === null || $closing < $less)
              && (! $hideZero || $closing != 0.0);
        }));

        usort($rows, fn (array $a, array $b) => match ($filters['sort_by'] ?? 'code') {
            'name' => [$a['name'], $a['code']] <=> [$b['name'], $b['code']],
            'balance' => [$b['closing'], $a['code']] <=> [$a['closing'], $b['code']],
            default => [$a['code'], $a['batch_no'] ?? '', $a['warehouse'] ?? ''] <=> [$b['code'], $b['batch_no'] ?? '', $b['warehouse'] ?? ''],
        });

        $columns = [ReportColumn::text('code', 'Item Code'), ReportColumn::text('name', 'Item Name')];
        if ($showBy === 'batch') {
            $columns[] = ReportColumn::text('batch_no', 'Batch No.');
            $columns[] = ReportColumn::date('expiry_date', 'Expiry Date');
        } elseif ($showBy === 'warehouse') {
            $columns[] = ReportColumn::text('warehouse', 'Warehouse');
        }
        $columns[] = ReportColumn::text('unit', 'Unit');
        $columns[] = ReportColumn::number('opening', 'Opening Balance', 3);
        $columns[] = ReportColumn::number('qty_in', 'Quantity In', 3);
        $columns[] = ReportColumn::number('qty_out', 'Quantity Out', 3);
        $columns[] = ReportColumn::number('closing', 'Closing Balance', 3);
        if ($showValue) {
            $columns[] = ReportColumn::number('average_cost', 'Average Cost', 4);
            $columns[] = ReportColumn::money('value', 'Cost Value');
        }

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->columns($columns);
        foreach ($rows as $row) {
            $result->addRow($row);
        }

        return ReportTotals::footer($result, ['opening', 'qty_in', 'qty_out', 'closing', ...($showValue ? ['value'] : [])], 'name');
    }

    /** @return array<string, mixed> */
    protected function row(object $item, object $b, $batches, $warehouses): array
    {
        $opening = round((float) $b->opening, 3);
        $in = round((float) $b->qty_in, 3);
        $out = round((float) $b->qty_out, 3);
        $closing = round($opening + $in - $out, 3);
        $batch = isset($b->item_batch_id) ? $batches->get($b->item_batch_id) : null;
        $warehouse = isset($b->warehouse_id) ? $warehouses->get($b->warehouse_id) : null;

        return [
            'code' => (string) $item->code,
            'name' => Ledger::localName($item->name_ar, $item->name_en),
            'batch_no' => $batch?->batch_no,
            'expiry_date' => $batch?->expiry_date,
            'warehouse' => $warehouse ? $warehouse->code.' - '.Ledger::localName($warehouse->name_ar, $warehouse->name_en) : null,
            'unit' => Ledger::localName($item->unit_ar, $item->unit_en),
            'opening' => $opening,
            'qty_in' => $in,
            'qty_out' => $out,
            'closing' => $closing,
            'average_cost' => round((float) $item->average_cost, 4),
            'value' => round($closing * (float) $item->average_cost, 2),
        ];
    }
}
