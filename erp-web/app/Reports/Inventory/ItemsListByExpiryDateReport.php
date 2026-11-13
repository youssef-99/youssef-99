<?php

namespace App\Reports\Inventory;

use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\TradeFields;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Items by Expiry Date (الأصناف حسب تاريخ الانتهاء): batches expiring in a date range, or already expired, with their
 * stock balance, days to expiry, last purchase price, selling price and cost value.
 * Filters: Status* (expiring in the range / already expired / all), Branches, Warehouse, Item, Item Category,
 * From* / To* (expiry date range), Only batches with a balance.
 */
class ItemsListByExpiryDateReport extends Report
{
    protected static string $group = 'inventory';

    protected static string $slug = 'items-list-by-expiry-date';

    protected static string $title = 'Items by Expiry Date';

    public function filters(): array
    {
        return [
            TradeFields::choice('status', 'Status', ['range' => 'Expiring in Period', 'expired' => 'Already Expired', 'all' => 'All'])->required(),
            TradeFields::branches(),
            TradeFields::warehouse(),
            TradeFields::item(),
            TradeFields::category(),
            ReportFields::fromDate('from', 'Expiry From')->required(),
            ReportFields::toDate('to', 'Expiry To')->required(),
            TradeFields::checkbox('with_balance', 'Only Batches with Balance'),
        ];
    }

    public function defaults(): array
    {
        return ['status' => 'range', 'from' => ReportFields::today(), 'to' => today()->addMonths(6)->toDateString(), 'with_balance' => 1];
    }

    public function run(array $filters): ReportResult
    {
        $status = $filters['status'] ?? 'range';
        $today = today()->toDateString();

        $balances = DB::table('stock_movements as m')
            ->whereNotNull('m.item_batch_id')
            ->when(TradeFields::branchIds($filters), fn (Builder $q, array $ids) => $q->whereIn('m.branch_id', $ids))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, $id) => $q->where('m.warehouse_id', $id))
            ->groupBy('m.item_batch_id')
            ->selectRaw('m.item_batch_id, SUM(m.qty_in - m.qty_out) as balance');

        $rows = DB::table('item_batches as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->leftJoin('units as u', 'u.id', '=', 'i.unit_id')
            ->leftJoinSub($balances, 'bal', 'bal.item_batch_id', '=', 'b.id')
            ->whereNotNull('b.expiry_date')
            ->when($filters['item_id'] ?? null, fn (Builder $q, $id) => $q->where('b.item_id', $id))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('i.category_id', $id))
            ->when($status === 'range', fn (Builder $q) => $q->where('b.expiry_date', '>=', Ledger::date($filters['from']))->where('b.expiry_date', '<', Ledger::nextDay($filters['to'])))
            ->when($status === 'expired', fn (Builder $q) => $q->where('b.expiry_date', '<', $today))
            ->when(TradeFields::flag($filters, 'with_balance'), fn (Builder $q) => $q->where('bal.balance', '>', 0))
            ->orderBy('b.expiry_date')->orderBy('i.code')
            ->get(['i.code', 'i.name_ar', 'i.name_en', 'i.average_cost', 'i.sale_price as item_price', 'u.name_ar as unit_ar', 'u.name_en as unit_en',
                'b.batch_no', 'b.expiry_date', 'b.last_purchase_price', 'b.sale_price', 'bal.balance']);

        $result = $this->result()
            ->info('Expiry Date', ReportColumn::formatDate($filters['from']).' - '.ReportColumn::formatDate($filters['to']))
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape()
            ->columns([
                ReportColumn::text('code', 'Item Code'),
                ReportColumn::text('name', 'Item Name'),
                ReportColumn::text('unit', 'Unit'),
                ReportColumn::text('batch_no', 'Batch No.'),
                ReportColumn::date('expiry_date', 'Expiry Date'),
                ReportColumn::number('days', 'Days to Expiry'),
                ReportColumn::number('balance', 'Balance', 3),
                ReportColumn::number('last_purchase_price', 'Last Purchase Price', 4),
                ReportColumn::number('sale_price', 'Selling Price', 4),
                ReportColumn::money('value', 'Cost Value'),
            ]);

        foreach ($rows as $row) {
            $balance = round((float) $row->balance, 3);
            $cost = (float) $row->last_purchase_price ?: (float) $row->average_cost;
            $result->addRow([
                'code' => $row->code,
                'name' => Ledger::localName($row->name_ar, $row->name_en),
                'unit' => Ledger::localName($row->unit_ar, $row->unit_en),
                'batch_no' => $row->batch_no,
                'expiry_date' => $row->expiry_date,
                'days' => (int) today()->diffInDays(Carbon::parse($row->expiry_date)->startOfDay(), false),
                'balance' => $balance,
                'last_purchase_price' => round((float) $row->last_purchase_price, 4),
                'sale_price' => round((float) $row->sale_price ?: (float) $row->item_price, 4),
                'value' => round($balance * $cost, 2),
            ]);
        }

        return ReportTotals::footer($result, ['balance', 'value'], 'name');
    }
}
