<?php

namespace App\Reports\Sales;

use App\Models\Promotion;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use App\Reports\Support\ReportTotals;
use App\Reports\Support\SalesQuery;
use App\Reports\Support\TradeFields;

/**
 * Promotions (العروض): usage of each promotion on posted sales lines — invoices, quantity, free quantity,
 * discount given and net sales (excl. VAT). Returns subtract.
 * Filters: Branches, Promotion, From*, To*, Include unused promotions.
 */
class PromotionsReport extends Report
{
    protected static string $group = 'sales';

    protected static string $slug = 'promotions';

    protected static string $title = 'Promotions';

    public function filters(): array
    {
        return [
            TradeFields::branches(),
            TradeFields::promotion(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
            TradeFields::checkbox('include_unused', 'Include Unused Promotions'),
        ];
    }

    public function defaults(): array
    {
        return ['from' => today()->startOfMonth()->toDateString(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $sign = SalesQuery::sign();
        $usage = SalesQuery::lines($filters)
            ->whereNotNull('l.promotion_id')
            ->when($filters['promotion_id'] ?? null, fn ($q, $id) => $q->where('l.promotion_id', $id))
            ->groupBy('l.promotion_id')
            ->select('l.promotion_id')
            ->selectRaw('COUNT(DISTINCT d.id) as documents')
            ->selectRaw('SUM('.SalesQuery::lineQty().') as quantity')
            ->selectRaw('SUM('.SalesQuery::lineFreeQty().') as free_qty')
            ->selectRaw("SUM(({$sign}) * l.discount) as discount")
            ->selectRaw('SUM('.SalesQuery::lineAmount(false).') as sales')
            ->get()
            ->keyBy('promotion_id');

        $promotions = Promotion::query()
            ->with(['item', 'category'])
            ->when($filters['promotion_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when(! TradeFields::flag($filters, 'include_unused'), fn ($q) => $q->whereKey($usage->keys()->all()))
            ->orderBy('code')
            ->get();

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Branches', TradeFields::branchNames($filters))
            ->landscape()
            ->columns([
                ReportColumn::text('code', 'Code'),
                ReportColumn::text('name', 'Promotion'),
                ReportColumn::text('target', 'Item / Category'),
                ReportColumn::text('type', 'Promotion Type'),
                ReportColumn::date('from_date', 'From Date'),
                ReportColumn::date('to_date', 'To Date'),
                ReportColumn::number('documents', 'Invoices'),
                ReportColumn::number('quantity', 'Quantity', 3),
                ReportColumn::number('free_qty', 'Free Qty', 3),
                ReportColumn::money('discount', 'Discount'),
                ReportColumn::money('sales', 'Net Sales'),
            ]);

        foreach ($promotions as $promotion) {
            $u = $usage->get($promotion->id);
            $result->addRow([
                'code' => $promotion->code,
                'name' => $promotion->name,
                'target' => $promotion->item?->label ?? $promotion->category?->label,
                'type' => static::t(str((string) $promotion->promotion_type)->headline()->toString()),
                'from_date' => $promotion->from_date,
                'to_date' => $promotion->to_date,
                'documents' => (int) ($u->documents ?? 0),
                'quantity' => round((float) ($u->quantity ?? 0), 3),
                'free_qty' => round((float) ($u->free_qty ?? 0), 3),
                'discount' => round((float) ($u->discount ?? 0), 2),
                'sales' => round((float) ($u->sales ?? 0), 2),
            ]);
        }

        return ReportTotals::footer($result, ['documents', 'quantity', 'free_qty', 'discount', 'sales'], 'name');
    }
}
