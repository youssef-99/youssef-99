<?php

namespace App\Reports\Accreditations;

use App\Models\Accreditation;
use App\Reports\Report;
use App\Reports\ReportResult;
use App\Reports\Support\Ledger;
use App\Reports\Support\ReportFields;
use App\Reports\Support\TrialBalanceBuilder;

/**
 * Letters of Credit Trial Balance (ميزان مراجعة الاعتمادات): opening, movement and closing per letter of credit
 * (same line attribution as the statement). Filters: Bank, Branch, From*, To*.
 */
class AccreditationsTrialBalanceReport extends Report
{
    protected static string $group = 'accreditations';

    protected static string $slug = 'accreditations-trial-balance';

    protected static string $title = 'Letters of Credit Trial Balance';

    public function filters(): array
    {
        return [
            ReportFields::bank(),
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
        $summary = Ledger::summary(AccreditationStatementReport::lines($filters['branch_id'] ?? null), 'acr.id', $filters['from'], $filters['to']);

        $rows = Accreditation::query()->with('bank')
            ->when($filters['bank_id'] ?? null, fn ($q, $id) => $q->where('bank_id', $id))
            ->orderBy('number')->get()
            ->map(fn (Accreditation $a) => [
                'number' => $a->number,
                'name' => trim(($a->bank?->name ?? '').($a->open_date ? ' - '.$a->open_date->format('d/m/Y') : ''), ' -'),
            ] + ($summary[$a->id] ?? ['opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0]));

        $result = $this->result()->period($filters['from'], $filters['to']);
        TrialBalanceBuilder::fill($result, $rows, nameLabel: 'Bank');

        return $result;
    }
}
