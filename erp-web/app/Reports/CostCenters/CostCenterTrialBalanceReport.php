<?php

namespace App\Reports\CostCenters;

use App\Models\CostCenter;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\TrialBalanceBuilder;

/**
 * Cost Centers Trial Balance (ميزان مراجعة مراكز التكلفة): opening, movement and closing of the lines
 * charged to each cost center. Filters: Account, Branch, From*, To*.
 */
class CostCenterTrialBalanceReport extends Report
{
    protected static string $group = 'cost-centers';

    protected static string $slug = 'cost-center-trial-balance';

    protected static string $title = 'Cost Centers Trial Balance';

    public function filters(): array
    {
        return [
            ReportFields::account(),
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
        $query = Ledger::query($filters['branch_id'] ?? null);
        if ($accountId = $filters['account_id'] ?? null) {
            $query->whereIn('l.account_id', Ledger::descendantIds((int) $accountId));
        }
        $summary = Ledger::summary($query, 'l.cost_center_id', $filters['from'], $filters['to']);

        $rows = CostCenter::query()->orderBy('code')->get()
            ->map(fn (CostCenter $c) => ['number' => $c->code, 'name' => $c->name] + ($summary[$c->id] ?? ['opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0]));

        $result = $this->result()->period($filters['from'], $filters['to']);
        TrialBalanceBuilder::fill($result, $rows, nameLabel: 'Cost Center');

        return $result;
    }
}
