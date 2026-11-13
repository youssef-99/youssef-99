<?php

namespace App\Services;

use App\Exceptions\ErpException;
use App\Models\Accreditation;
use App\Models\AccreditationFee;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * Letters of credit (الاعتمادات المستندية) and their fees (مصاريف الاعتماد).
 *
 * Fee posting: Dr L/C account (the cost is capitalised and later credited from the L/C to inventory by
 * the foreign purchase invoice), Cr the fee's credit account (default: the L/C bank's account).
 */
class AccreditationService
{
    public static function close(Accreditation $accreditation, mixed $date = null): Accreditation
    {
        $accreditation->forceFill(['status' => 'closed', 'close_date' => $date ?? $accreditation->close_date ?? now()->toDateString()])->save();

        return $accreditation;
    }

    public static function reopen(Accreditation $accreditation): Accreditation
    {
        $accreditation->forceFill(['status' => 'open'])->save();

        return $accreditation;
    }

    /** Posted fees total (local currency). */
    public static function feesTotal(Accreditation $accreditation): float
    {
        return round((float) $accreditation->fees()->where('is_posted', true)->sum('amount'), 2);
    }

    public static function post(AccreditationFee $fee): AccreditationFee
    {
        if ($fee->is_posted) {
            throw new ErpException(__('This document is already posted.'));
        }

        $fee->loadMissing('accreditation.bank');
        $accreditation = $fee->accreditation ?? throw new ErpException(__('Select a letter of credit.'));

        if (! $accreditation->isOpen()) {
            throw new ErpException(__('The letter of credit is closed.'));
        }

        $debit = $accreditation->lc_account_id ?? throw new ErpException(__('The letter of credit has no L/C account.'));
        $credit = $fee->credit_account_id ?: $accreditation->bank?->account_id;
        if (! $credit) {
            throw new ErpException(__('Select the credit account (bank) for the fee.'));
        }

        if ((float) $fee->amount <= 0) {
            throw new ErpException(__('The amount must be greater than zero.'));
        }

        return DB::transaction(function () use ($fee, $accreditation, $debit, $credit) {
            PostingLock::lock($fee, expectPosted: false);
            $desc = __('L/C Fee').' #'.($fee->number ?: $fee->id).' - '.$accreditation->number.($fee->fee_type ? ' - '.__(static::feeTypes()[$fee->fee_type] ?? $fee->fee_type) : '');
            $line = fn (int $account, float $amount) => SalesDocumentService::side($account, $amount, $desc) + ['accreditation_id' => $accreditation->id];

            $entry = JournalService::record(
                $fee,
                'lc_fee',
                $fee->branch_id ?? auth()->user()?->default_branch_id ?? Branch::query()->value('id'),
                $fee->date,
                [$line($debit, (float) $fee->amount), $line($credit, -(float) $fee->amount)],
                $desc,
            );

            $fee->forceFill(['is_posted' => true, 'journal_entry_id' => $entry->id, 'credit_account_id' => $credit])->save();

            return $fee;
        });
    }

    public static function unpost(AccreditationFee $fee): AccreditationFee
    {
        return DB::transaction(function () use ($fee): AccreditationFee {
            PostingLock::lock($fee, expectPosted: true);
            JournalService::removeFor($fee);
            $fee->forceFill(['is_posted' => false, 'journal_entry_id' => null])->save();

            return $fee;
        });
    }

    /** @return array<string, string> */
    public static function feeTypes(): array
    {
        return [
            'bank_charges' => 'Bank Charges',
            'shipping' => 'Shipping',
            'customs' => 'Customs',
            'insurance' => 'Insurance',
            'clearance' => 'Customs Clearance',
            'other' => 'Other',
        ];
    }
}
