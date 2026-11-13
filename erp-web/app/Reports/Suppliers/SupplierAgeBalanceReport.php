<?php

namespace App\Reports\Suppliers;

use App\Models\Supplier;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\PartyLedger;
use App\Reports\Support\ReportFields;
use Illuminate\Support\Collection;

/**
 * Suppliers Debt Ageing (أعمار ديون الموردين): amounts owed to each supplier at To Date split into age buckets.
 * Filters: From Supplier*, To Supplier* (code range), Branch, From*, To*, Debt Ageing* (days per bucket).
 */
class SupplierAgeBalanceReport extends Report
{
    protected static string $group = 'suppliers';

    protected static string $slug = 'supplier-age-balance';

    protected static string $title = 'Suppliers Debt Ageing';

    public function filters(): array
    {
        return [
            ReportFields::supplier('from_supplier_id', 'From Supplier')->required(),
            ReportFields::supplier('to_supplier_id', 'To Supplier')->required(),
            ReportFields::branch(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
            ReportFields::integer('ageing_days', 'Debt Ageing')->required()->suffix(static::t('days')),
        ];
    }

    public function defaults(): array
    {
        $suppliers = static::ordered();

        return [
            'from_supplier_id' => $suppliers->first()?->id,
            'to_supplier_id' => $suppliers->last()?->id,
            'from' => ReportFields::fiscalYearStart(),
            'to' => ReportFields::today(),
            'ageing_days' => 30,
        ];
    }

    public function run(array $filters): ReportResult
    {
        $suppliers = static::ordered()->values();
        $start = $suppliers->search(fn (Supplier $s) => $s->id == $filters['from_supplier_id']);
        $end = $suppliers->search(fn (Supplier $s) => $s->id == $filters['to_supplier_id']);
        if ($start === false || $end === false) {
            $range = collect();
        } else {
            [$start, $end] = [min($start, $end), max($start, $end)];
            $range = $suppliers->slice($start, $end - $start + 1);
        }

        return PartyLedger::aging($this->result(), $range, $filters['from'], $filters['to'], (int) $filters['ageing_days'], -1, $filters['branch_id'] ?? null);
    }

    /** Suppliers in natural code order (so "10" comes after "9"). */
    protected static function ordered(): Collection
    {
        return Supplier::query()->get()->sortBy('code', SORT_NATURAL)->values();
    }
}
