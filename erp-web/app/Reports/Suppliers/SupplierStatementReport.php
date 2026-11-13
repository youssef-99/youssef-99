<?php

namespace App\Reports\Suppliers;

use App\Models\Supplier;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\PartyLedger;
use App\Reports\Support\ReportFields;

/**
 * Supplier Statement (كشف حساب مورد): the supplier's sub-ledger account with opening balance,
 * running balance (debit - credit, so amounts owed are negative), Total and Movement During Period.
 * Filters: Supplier*, Branch, From*, To*.
 */
class SupplierStatementReport extends Report
{
    protected static string $group = 'suppliers';

    protected static string $slug = 'supplier-statement';

    protected static string $title = 'Supplier Statement';

    public function filters(): array
    {
        return [
            ReportFields::supplier()->required(),
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
        $supplier = Supplier::query()->findOrFail($filters['supplier_id']);

        return PartyLedger::statement($this->result(), $supplier, $filters['from'], $filters['to'], $filters['branch_id'] ?? null);
    }
}
