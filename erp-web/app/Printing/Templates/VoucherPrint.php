<?php

namespace App\Printing\Templates;

use App\Enums\VoucherType;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Printing\AmountInWords;
use App\Printing\PrintTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Receipt / payment vouchers (cash and cheque) and credit / debit notices.
 */
class VoucherPrint extends PrintTemplate
{
    public function find(int $id): Model
    {
        return Voucher::query()
            ->with(['branch.company', 'account', 'bank', 'lines.account', 'lines.bank', 'lines.costCenter', 'lines.salesman'])
            ->findOrFail($id);
    }

    public function build(Model $record, Request $request): array
    {
        /** @var Voucher $record */
        $type = VoucherType::tryFrom((string) $record->type);
        $isCheque = in_array($type, [VoucherType::ReceiptCheque, VoucherType::PaymentCheque], true);
        $isReceipt = in_array($type, [VoucherType::ReceiptCash, VoucherType::ReceiptCheque], true);

        $columns = [
            'no' => ['label' => __('No.'), 'align' => 'start'],
            'account_no' => ['label' => __('Account No.'), 'align' => 'start'],
            'account' => ['label' => __('Account Name'), 'align' => 'start'],
            'description' => ['label' => __('Line Description'), 'align' => 'start'],
            'cost_center' => ['label' => __('Cost Center'), 'align' => 'start'],
        ];

        if ($isCheque) {
            $columns += [
                'cheque_no' => ['label' => __('Cheque No.'), 'align' => 'start'],
                'bank' => ['label' => __('Bank'), 'align' => 'start'],
                'due_date' => ['label' => __('Due Date'), 'align' => 'start'],
            ];
        }

        $columns['amount'] = ['label' => __('Amount'), 'align' => 'end'];

        $rows = $record->lines->values()->map(fn (VoucherLine $line, int $index): array => [
            'no' => $index + 1,
            'account_no' => $line->account?->number,
            'account' => $line->account?->name,
            'description' => $line->description,
            'cost_center' => $line->costCenter?->name,
            'cheque_no' => $line->cheque_no ?: $record->cheque_no,
            'bank' => ($line->bank ?? $record->bank)?->name,
            'due_date' => static::date($line->due_date ?? $record->due_date),
            'amount' => static::money($line->amount),
        ])->all();

        $amount = (float) $record->amount ?: (float) $record->lines->sum('amount');

        return [
            'title' => $type?->label() ?? __('Voucher'),
            'subtitle' => null,
            'status' => $this->statusLabel($record),
            'number' => $record->number,
            'fields' => $this->fields([
                ['Voucher No.', $record->number],
                ['Voucher Date', static::date($record->date)],
                ['Branch', $record->branch?->name],
                ['Received By', $record->received_by],
                ['Pay To', $record->pay_to],
                [$isReceipt ? 'Debit Account' : 'Credit Account', $record->account ? $record->account->number.' - '.$record->account->name : null],
                ['Manual Document No.', $record->manual_document_no],
                ['Bank', $record->bank?->name],
                ['Cheque No.', $record->cheque_no],
                ['Due Date', static::date($record->due_date)],
            ]),
            'partyTitle' => null,
            'partyFields' => [],
            'columns' => $columns,
            'rows' => $rows,
            'footerRow' => ['account' => __('Total'), 'amount' => static::money($amount)],
            'totals' => [
                [__('Amount'), static::money($amount), true],
            ],
            'grandTotal' => static::money($amount),
            'amountInWords' => AmountInWords::format($amount),
            'notes' => $record->description,
            'qr' => null,
            'signatures' => [__('Accountant'), __('Receiver'), __('Financial Manager')],
            'record' => $record,
        ];
    }
}
