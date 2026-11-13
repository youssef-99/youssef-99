<?php

namespace App\Reports\Salesmen;

use App\Models\Salesman;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\StatementBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Salesman Statement (كشف حساب مندوب): receivable movements attributed to the salesman — posted lines on
 * customer accounts tagged with the salesman (his invoices, returns and the collections recorded for him).
 * Filters: Salesman*, Branch, From*, To*.
 */
class SalesmanStatementReport extends Report
{
    protected static string $group = 'salesmen';

    protected static string $slug = 'salesman-statement';

    protected static string $title = 'Salesman Statement';

    public function filters(): array
    {
        return [
            ReportFields::salesman()->required(),
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
        $salesman = Salesman::query()->findOrFail($filters['salesman_id']);
        $query = Ledger::query($filters['branch_id'] ?? null)
            ->where('l.salesman_id', $salesman->id)
            ->whereIn('l.account_id', DB::table('accounts')->where('type', 'customer')->select('id'));

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Salesman', $salesman->label);

        StatementBuilder::fill($result, $query, $filters['from'], $filters['to'], showAccount: true);

        return $result;
    }
}
