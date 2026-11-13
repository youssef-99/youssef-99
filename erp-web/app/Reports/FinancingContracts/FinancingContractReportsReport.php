<?php

namespace App\Reports\FinancingContracts;

use App\Models\FinancingContract;
use App\Models\FinancingInstallment;
use App\Reports\Report;
use App\Reports\ReportColumn;
use App\Reports\ReportResult;
use App\Reports\Support\ReportFields;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Financing Contracts Report (تقرير عقود التمويل): murabaha contracts with installments paid / remaining /
 * overdue as of a date. Summary = one row per contract; Detail = installments grouped by contract.
 * Filters: Financing Contract, Bank, Branch, As Of Date*, Report Type*.
 */
class FinancingContractReportsReport extends Report
{
    protected static string $group = 'financing-contracts';

    protected static string $slug = 'financing-contract-reports';

    protected static string $title = 'Financing Contracts Report';

    public function filters(): array
    {
        return [
            ReportFields::financingContract(),
            ReportFields::bank(),
            ReportFields::branch(),
            ReportFields::toDate('to', 'As Of Date')->required(),
            ReportFields::reportType()->required(),
        ];
    }

    public function defaults(): array
    {
        return ['to' => ReportFields::today(), 'report_type' => 'summary'];
    }

    public function run(array $filters): ReportResult
    {
        $asOf = Carbon::parse($filters['to'])->endOfDay();
        $contracts = FinancingContract::query()->with(['bank', 'installments' => fn ($q) => $q->orderBy('sequence')])
            ->when($filters['financing_contract_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['bank_id'] ?? null, fn ($q, $id) => $q->where('bank_id', $id))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->orderBy('contract_date')->orderBy('number')
            ->get();

        $result = $this->result()->info('As Of Date', ReportColumn::formatDate($filters['to']))->landscape();

        $filters['report_type'] === 'detail'
            ? $this->detail($result, $contracts, $asOf)
            : $this->summary($result, $contracts, $asOf);

        return $result;
    }

    /** @param  Collection<int, FinancingContract>  $contracts */
    protected function summary(ReportResult $result, Collection $contracts, Carbon $asOf): void
    {
        $result->columns([
            ReportColumn::text('number', 'Contract No.'),
            ReportColumn::date('date', 'Contract Date'),
            ReportColumn::text('bank', 'Bank'),
            ReportColumn::money('murabaha', 'Murabaha Amount'),
            ReportColumn::money('profit', 'Bank Profit Margin'),
            ReportColumn::number('count', 'Installments'),
            ReportColumn::money('total', 'Installments Total'),
            ReportColumn::number('paid_count', 'Paid Installments'),
            ReportColumn::money('paid', 'Paid'),
            ReportColumn::money('remaining', 'Remaining'),
            ReportColumn::money('overdue', 'Overdue'),
        ]);

        foreach ($contracts as $contract) {
            $s = static::stats($contract->installments, $asOf);
            $result->addRow([
                'number' => $contract->number,
                'date' => $contract->contract_date,
                'bank' => $contract->bank?->name,
                'murabaha' => (float) $contract->murabaha_amount,
                'profit' => (float) $contract->bank_profit_margin,
            ] + $s);
        }

        $total = ['bank' => static::t('Total')];
        foreach (['murabaha', 'profit', 'count', 'total', 'paid_count', 'paid', 'remaining', 'overdue'] as $key) {
            $total[$key] = $result->sum($key);
        }
        $result->addTotal($total);
    }

    /** @param  Collection<int, FinancingContract>  $contracts */
    protected function detail(ReportResult $result, Collection $contracts, Carbon $asOf): void
    {
        $result->columns([
            ReportColumn::number('sequence', 'Installment No.'),
            ReportColumn::date('due_date', 'Due Date'),
            ReportColumn::money('amount', 'Amount'),
            ReportColumn::money('paid', 'Paid'),
            ReportColumn::money('remaining', 'Remaining'),
            ReportColumn::text('status', 'Status'),
            ReportColumn::date('paid_at', 'Payment Date'),
        ]);

        $grand = ['amount' => 0.0, 'paid' => 0.0, 'remaining' => 0.0];
        foreach ($contracts as $contract) {
            $result->addGroup(implode(' - ', array_filter([$contract->number, $contract->bank?->name, $contract->contract_date?->format('d/m/Y')])));
            $sub = ['amount' => 0.0, 'paid' => 0.0, 'remaining' => 0.0];
            foreach ($contract->installments as $installment) {
                $paid = static::isPaid($installment, $asOf);
                $amount = (float) $installment->amount;
                $status = $paid ? 'Paid' : ($installment->due_date->lte($asOf) ? 'Overdue' : 'Not Due');
                $cells = [
                    'sequence' => $installment->sequence,
                    'due_date' => $installment->due_date,
                    'amount' => $amount,
                    'paid' => $paid ? $amount : 0.0,
                    'remaining' => $paid ? 0.0 : $amount,
                    'status' => static::t($status),
                    'paid_at' => $paid ? $installment->paid_at : null,
                ];
                $result->addRow($cells);
                foreach ($sub as $k => $v) {
                    $sub[$k] = round($v + $cells[$k], 2);
                }
            }
            $result->addSubtotal(['status' => static::t('Total')] + $sub);
            foreach ($grand as $k => $v) {
                $grand[$k] = round($v + $sub[$k], 2);
            }
        }

        $result->addTotal(['status' => static::t('Total')] + $grand);
    }

    /**
     * @param  Collection<int, FinancingInstallment>  $installments
     * @return array{count: int, total: float, paid_count: int, paid: float, remaining: float, overdue: float}
     */
    public static function stats(Collection $installments, Carbon $asOf): array
    {
        $out = ['count' => $installments->count(), 'total' => 0.0, 'paid_count' => 0, 'paid' => 0.0, 'remaining' => 0.0, 'overdue' => 0.0];
        foreach ($installments as $installment) {
            $amount = (float) $installment->amount;
            $out['total'] += $amount;
            if (static::isPaid($installment, $asOf)) {
                $out['paid_count']++;
                $out['paid'] += $amount;
            } else {
                $out['remaining'] += $amount;
                if ($installment->due_date->lte($asOf)) {
                    $out['overdue'] += $amount;
                }
            }
        }
        foreach (['total', 'paid', 'remaining', 'overdue'] as $key) {
            $out[$key] = round($out[$key], 2);
        }

        return $out;
    }

    /** Paid on or before the as-of date (an installment paid without a date counts as paid). */
    protected static function isPaid(FinancingInstallment $installment, Carbon $asOf): bool
    {
        return $installment->is_paid && (! $installment->paid_at || $installment->paid_at->lte($asOf));
    }
}
