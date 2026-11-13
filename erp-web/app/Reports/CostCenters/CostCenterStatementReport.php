<?php

namespace App\Reports\CostCenters;

use App\Models\CostCenter;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\StatementBuilder;

/**
 * Cost Center Statement (كشف حساب مركز تكلفة): every posted line charged to the cost center
 * (and its sub-centers), with opening and running balance. Filters: Cost Center*, Account, Branch, From*, To*.
 */
class CostCenterStatementReport extends Report
{
    protected static string $group = 'cost-centers';

    protected static string $slug = 'cost-center-statement';

    protected static string $title = 'Cost Center Statement';

    public function filters(): array
    {
        return [
            ReportFields::costCenter()->required(),
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
        $center = CostCenter::query()->findOrFail($filters['cost_center_id']);
        $query = Ledger::query($filters['branch_id'] ?? null)->whereIn('l.cost_center_id', static::descendantIds($center->id));
        if ($accountId = $filters['account_id'] ?? null) {
            $query->whereIn('l.account_id', Ledger::descendantIds((int) $accountId));
        }

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Cost Center', $center->label);

        StatementBuilder::fill($result, $query, $filters['from'], $filters['to'], showAccount: true);

        return $result;
    }

    /** @return array<int, int> the cost center and all its sub-centers */
    public static function descendantIds(int $id): array
    {
        $children = CostCenter::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [];
        $stack = [$id];
        while ($stack) {
            $current = array_pop($stack);
            $ids[] = $current;
            foreach ($children->get($current, collect()) as $child) {
                $stack[] = $child->id;
            }
        }

        return $ids;
    }
}
