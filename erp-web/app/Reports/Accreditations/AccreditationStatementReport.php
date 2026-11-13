<?php

namespace App\Reports\Accreditations;

use App\Models\Accreditation;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\StatementBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Letter of Credit Statement (كشف حساب اعتماد مستندي).
 *
 * A line belongs to a letter of credit when it is tagged with it (journal line accreditation_id) or comes from a
 * foreign purchase invoice / fee of that letter of credit, and it is posted to the letter's L/C account
 * (any account when the letter has none). Filters: Letter of Credit*, Branch, From*, To*.
 */
class AccreditationStatementReport extends Report
{
    protected static string $group = 'accreditations';

    protected static string $slug = 'accreditation-statement';

    protected static string $title = 'Letter of Credit Statement';

    public function filters(): array
    {
        return [
            ReportFields::accreditation()->required(),
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
        $accreditation = Accreditation::query()->with(['bank', 'supplier', 'lcAccount'])->findOrFail($filters['accreditation_id']);

        $result = $this->result()
            ->period($filters['from'], $filters['to'])
            ->info('Letter of Credit', $accreditation->number)
            ->info('Bank', $accreditation->bank?->name)
            ->info('Supplier', $accreditation->supplier?->name)
            ->info('Account', $accreditation->lcAccount ? $accreditation->lcAccount->number.' - '.$accreditation->lcAccount->name : null);

        StatementBuilder::fill($result, static::lines($filters['branch_id'] ?? null)->where('acr.id', $accreditation->id), $filters['from'], $filters['to']);

        return $result;
    }

    /** Posted lines attributed to a letter of credit; the letter's id is `acr.id`. */
    public static function lines(?int $branchId = null): Builder
    {
        return Ledger::query($branchId)
            ->leftJoin('purchase_documents as pd', fn (JoinClause $j) => $j->on('pd.id', '=', 'e.source_id')->where('e.source_type', '=', 'purchase_document'))
            ->leftJoin('accreditation_fees as af', fn (JoinClause $j) => $j->on('af.id', '=', 'e.source_id')->where('e.source_type', '=', 'accreditation_fee'))
            ->join('accreditations as acr', fn (JoinClause $j) => $j->on('acr.id', '=', DB::raw('COALESCE(l.accreditation_id, pd.accreditation_id, af.accreditation_id)')))
            ->where(fn (Builder $w) => $w->whereColumn('l.account_id', 'acr.lc_account_id')
                ->orWhere(fn (Builder $x) => $x->whereNull('acr.lc_account_id')->whereNotNull('l.accreditation_id')));
    }
}
