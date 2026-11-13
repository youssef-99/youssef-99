<?php

namespace App\Printing\Templates;

use App\Models\FinancingContract;
use App\Models\FinancingInstallment;
use App\Printing\AmountInWords;
use App\Printing\PrintTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Murabaha financing contract (عقد تمويل - مرابحة) with its installments schedule.
 */
class FinancingContractPrint extends PrintTemplate
{
    public function find(int $id): Model
    {
        return FinancingContract::query()
            ->with(['branch.company', 'bank', 'loanAccount', 'interestAccount', 'installments'])
            ->findOrFail($id);
    }

    public function build(Model $record, Request $request): array
    {
        /** @var FinancingContract $record */
        $amount = (float) $record->murabaha_amount;
        $margin = (float) $record->bank_profit_margin;
        $profit = round($amount * $margin / 100, 2);
        $installments = $record->installments;
        $total = $installments->isNotEmpty() ? (float) $installments->sum('amount') : round($amount + $profit, 2);
        $paid = (float) $installments->where('is_paid', true)->sum('amount');

        $rows = $installments->values()->map(fn (FinancingInstallment $installment, int $index): array => [
            'no' => $installment->sequence ?: $index + 1,
            'due_date' => static::date($installment->due_date),
            'amount' => static::money($installment->amount),
            'status' => $installment->is_paid ? __('Paid') : __('Not Paid'),
            'paid_at' => static::date($installment->paid_at),
        ])->all();

        return [
            'title' => __('Financing Contract'),
            'subtitle' => __('Murabaha'),
            'status' => $record->getAttribute('is_posted') === null ? null : $this->statusLabel($record),
            'number' => $record->number,
            'fields' => $this->fields([
                ['Contract No.', $record->number],
                ['Contract Date', static::date($record->contract_date)],
                ['Branch', $record->branch?->name],
                ['Bank', $record->bank?->name],
                ['Murabaha Amount', static::money($amount)],
                ['Bank Profit Margin', static::percent($margin)],
                ['Installments Count', $record->installments_count],
                ['Installment Amount', static::money($record->installment_amount)],
                ['First Installment Date', static::date($record->first_installment_date)],
                ['Loan Account', $record->loanAccount ? $record->loanAccount->number.' - '.$record->loanAccount->name : null],
                ['Profit Account', $record->interestAccount ? $record->interestAccount->number.' - '.$record->interestAccount->name : null],
            ]),
            'partyTitle' => null,
            'partyFields' => [],
            'columns' => [
                'no' => ['label' => __('Installment No.'), 'align' => 'start'],
                'due_date' => ['label' => __('Due Date'), 'align' => 'start'],
                'amount' => ['label' => __('Amount'), 'align' => 'end'],
                'status' => ['label' => __('Payment Status'), 'align' => 'start'],
                'paid_at' => ['label' => __('Paid Date'), 'align' => 'start'],
            ],
            'rows' => $rows,
            'footerRow' => ['due_date' => __('Total'), 'amount' => static::money($total)],
            'totals' => [
                [__('Murabaha Amount'), static::money($amount), false],
                [__('Bank Profit'), static::money($profit), false],
                [__('Total Payable'), static::money($total), true],
                [__('Paid Installments'), static::money($paid), false],
                [__('Remaining'), static::money(round($total - $paid, 2)), true],
            ],
            'grandTotal' => static::money($total),
            'amountInWords' => AmountInWords::format($total),
            'notes' => $record->notes,
            'qr' => null,
            'signatures' => [__('Accountant'), __('Financial Manager'), __('Approved By')],
            'record' => $record,
        ];
    }
}
