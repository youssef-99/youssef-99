<?php

namespace App\Reports\Suppliers;

use App\Models\Supplier;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\PartyLedger;
use App\Reports\Support\ReportFields;

/**
 * Suppliers Trial Balance (ميزان مراجعة الموردين): opening, movement and closing per supplier.
 * Filters: Supplier Group, Branch, From*, To*.
 */
class SupplierTrialBalanceReport extends Report
{
    protected static string $group = 'suppliers';

    protected static string $slug = 'supplier-trial-balance';

    protected static string $title = 'Suppliers Trial Balance';

    public function filters(): array
    {
        return [
            ReportFields::supplierGroup(),
            ReportFields::branch(),
            ReportFields::fromDate()->required(),
            ReportFields::toDate()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['from' => ReportFields::fiscalYearStart(), 'to' => ReportFields::today()];
    }

    public function run(array $filters): ReportResult
    {
        $suppliers = Supplier::query()
            ->when($filters['supplier_group_id'] ?? null, fn ($q, $id) => $q->where('supplier_group_id', $id))
            ->orderBy('code')
            ->get();

        return PartyLedger::trialBalance($this->result(), $suppliers, $filters['from'], $filters['to'], $filters['branch_id'] ?? null);
    }
}
