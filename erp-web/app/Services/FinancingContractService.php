<?php

namespace App\Services;

use App\Exceptions\ErpException;
use App\Models\Branch;
use App\Models\FinancingContract;
use App\Models\FinancingInstallment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Murabaha financing contracts (عقود التمويل - المرابحة).
 *
 * Total payable = murabaha amount + bank profit (margin % of the amount), paid in equal monthly
 * installments; the last installment absorbs rounding so the schedule sums to the total.
 *
 * Contract entry:     Dr funding account (amount), Dr profit account (profit) / Cr loan account (total).
 * Installment entry:  Dr loan account / Cr payment account (cash or bank).
 */
class FinancingContractService
{
    public static function profit(float $amount, float $marginPercent): float
    {
        return round($amount * $marginPercent / 100, 2);
    }

    public static function totalPayable(float $amount, float $marginPercent): float
    {
        return round($amount + static::profit($amount, $marginPercent), 2);
    }

    public static function installmentAmount(float $amount, float $marginPercent, int $count): float
    {
        return $count > 0 ? round(static::totalPayable($amount, $marginPercent) / $count, 2) : 0.0;
    }

    /**
     * Monthly schedule starting at the first due date.
     *
     * @return array<int, array{sequence: int, due_date: string, amount: float}>
     */
    public static function schedule(float $amount, float $marginPercent, int $count, CarbonInterface|string $firstDueDate): array
    {
        if ($count < 1) {
            return [];
        }

        $total = static::totalPayable($amount, $marginPercent);
        $installment = static::installmentAmount($amount, $marginPercent, $count);
        $first = Carbon::parse($firstDueDate);
        $rows = [];
        $allocated = 0.0;

        for ($i = 1; $i <= $count; $i++) {
            $value = $i === $count ? round($total - $allocated, 2) : $installment;
            $allocated = round($allocated + $value, 2);
            $rows[] = [
                'sequence' => $i,
                'due_date' => $first->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                'amount' => $value,
            ];
        }

        return $rows;
    }

    /** Recompute the installment amount and regenerate the schedule of an unposted contract. */
    public static function recalculate(FinancingContract $contract): FinancingContract
    {
        if ($contract->is_posted) {
            return $contract;
        }

        return DB::transaction(function () use ($contract): FinancingContract {
            $amount = (float) $contract->murabaha_amount;
            $margin = (float) $contract->bank_profit_margin;
            $count = max(1, (int) $contract->installments_count);
            $firstDue = $contract->first_installment_date ?? $contract->contract_date->copy()->addMonthNoOverflow();

            $contract->forceFill([
                'installments_count' => $count,
                'installment_amount' => static::installmentAmount($amount, $margin, $count),
                'first_installment_date' => $firstDue,
            ])->saveQuietly();

            $contract->installments()->delete();
            foreach (static::schedule($amount, $margin, $count, $firstDue) as $row) {
                $contract->installments()->create($row);
            }

            return $contract->refresh();
        });
    }

    public static function post(FinancingContract $contract): FinancingContract
    {
        if ($contract->is_posted) {
            throw new ErpException(__('This document is already posted.'));
        }

        if ((float) $contract->murabaha_amount <= 0) {
            throw new ErpException(__('Enter the murabaha amount.'));
        }

        foreach (['funding_account_id' => 'Funding Account', 'loan_account_id' => 'Loan Account', 'interest_account_id' => 'Profit Account'] as $column => $label) {
            if (! $contract->{$column}) {
                throw new ErpException(__('Select the :field.', ['field' => __($label)]));
            }
        }

        return DB::transaction(function () use ($contract): FinancingContract {
            if ($contract->installments()->doesntExist()) {
                static::recalculate($contract);
            }

            $amount = (float) $contract->murabaha_amount;
            $profit = static::profit($amount, (float) $contract->bank_profit_margin);
            $desc = __('Financing Contract').' #'.$contract->number;

            $entry = JournalService::record($contract, 'financing', static::branchId($contract), $contract->contract_date, [
                SalesDocumentService::side($contract->funding_account_id, $amount, $desc),
                SalesDocumentService::side($contract->interest_account_id, $profit, $desc),
                SalesDocumentService::side($contract->loan_account_id, -($amount + $profit), $desc),
            ], $desc);

            $contract->forceFill(['is_posted' => true, 'posted_at' => now(), 'journal_entry_id' => $entry->id])->saveQuietly();

            return $contract;
        });
    }

    public static function unpost(FinancingContract $contract): FinancingContract
    {
        if ($contract->installments()->where('is_paid', true)->exists()) {
            throw new ErpException(__('Some installments of this contract were already paid.'));
        }

        JournalService::removeFor($contract);
        $contract->forceFill(['is_posted' => false, 'posted_at' => null, 'journal_entry_id' => null])->saveQuietly();

        return $contract;
    }

    /** Pay an installment from a cash/bank account: Dr loan account, Cr payment account. */
    public static function payInstallment(FinancingInstallment $installment, int $paymentAccountId, CarbonInterface|string|null $date = null): FinancingInstallment
    {
        $contract = $installment->financingContract;

        if (! $contract->is_posted) {
            throw new ErpException(__('Post the contract before paying installments.'));
        }

        if ($installment->is_paid) {
            throw new ErpException(__('This installment is already paid.'));
        }

        $date = Carbon::parse($date ?? now());
        $desc = __('Installment :sequence of financing contract #:number', ['sequence' => $installment->sequence, 'number' => $contract->number]);

        return DB::transaction(function () use ($installment, $contract, $paymentAccountId, $date, $desc): FinancingInstallment {
            $entry = JournalService::append($contract, 'financing_installment', static::branchId($contract), $date, [
                SalesDocumentService::side($contract->loan_account_id, (float) $installment->amount, $desc),
                SalesDocumentService::side($paymentAccountId, -(float) $installment->amount, $desc),
            ], $desc);

            $installment->forceFill([
                'is_paid' => true,
                'paid_at' => $date,
                'payment_account_id' => $paymentAccountId,
                'journal_entry_id' => $entry->id,
            ])->save();

            return $installment;
        });
    }

    public static function cancelInstallmentPayment(FinancingInstallment $installment): FinancingInstallment
    {
        return DB::transaction(function () use ($installment): FinancingInstallment {
            $installment->journalEntry?->delete();
            $installment->forceFill(['is_paid' => false, 'paid_at' => null, 'payment_account_id' => null, 'journal_entry_id' => null])->save();

            return $installment;
        });
    }

    protected static function branchId(FinancingContract $contract): int
    {
        return (int) ($contract->branch_id ?? auth()->user()?->default_branch_id ?? Branch::query()->value('id'));
    }
}
