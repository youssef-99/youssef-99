<?php

namespace App\Printing\Templates;

use App\Models\ChequeCollection;
use App\Models\VoucherLine;
use App\Printing\AmountInWords;
use App\Printing\PrintTemplate;
use App\Services\VoucherService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Cheque collection with its cheques (collected ones once posted, the collectable ones while a draft).
 */
class ChequeCollectionPrint extends PrintTemplate
{
    public function find(int $id): Model
    {
        return ChequeCollection::query()
            ->with(['branch.company', 'bankAccount', 'cheques.voucher', 'cheques.bank', 'cheques.account'])
            ->findOrFail($id);
    }

    /** @return Collection<int, VoucherLine> */
    protected function cheques(ChequeCollection $collection): Collection
    {
        if ($collection->is_posted || $collection->cheques->isNotEmpty()) {
            return $collection->cheques->sortBy('due_date')->values();
        }

        if (! $collection->branch_id || ! $collection->from_date || ! $collection->to_date) {
            return collect();
        }

        return VoucherService::collectableCheques($collection->branch_id, $collection->from_date->toDateString(), $collection->to_date->toDateString())->values();
    }

    public function build(Model $record, Request $request): array
    {
        /** @var ChequeCollection $record */
        $cheques = $this->cheques($record);
        $total = (float) $record->total ?: (float) $cheques->sum('amount');

        $rows = $cheques->map(fn (VoucherLine $cheque, int $index): array => [
            'no' => $index + 1,
            'cheque_no' => $cheque->cheque_no,
            'bank' => $cheque->bank?->name,
            'due_date' => static::date($cheque->due_date),
            'voucher_no' => $cheque->voucher?->number,
            'account' => $cheque->account ? $cheque->account->number.' - '.$cheque->account->name : null,
            'amount' => static::money($cheque->amount),
        ])->all();

        return [
            'title' => __('Cheque Collection'),
            'subtitle' => null,
            'status' => $this->statusLabel($record),
            'number' => $record->number,
            'fields' => $this->fields([
                ['Document No.', $record->number],
                ['Date', static::date($record->date)],
                ['Branch', $record->branch?->name],
                ['From Date', static::date($record->from_date)],
                ['To Date', static::date($record->to_date)],
                ['Bank Account', $record->bankAccount ? $record->bankAccount->number.' - '.$record->bankAccount->name : null],
            ]),
            'partyTitle' => null,
            'partyFields' => [],
            'columns' => [
                'no' => ['label' => __('No.'), 'align' => 'start'],
                'cheque_no' => ['label' => __('Cheque No.'), 'align' => 'start'],
                'bank' => ['label' => __('Bank'), 'align' => 'start'],
                'due_date' => ['label' => __('Due Date'), 'align' => 'start'],
                'voucher_no' => ['label' => __('Voucher No.'), 'align' => 'start'],
                'account' => ['label' => __('Account'), 'align' => 'start'],
                'amount' => ['label' => __('Amount'), 'align' => 'end'],
            ],
            'rows' => $rows,
            'footerRow' => ['account' => __('Total'), 'amount' => static::money($total)],
            'totals' => [
                [__('Cheques Count'), (string) count($rows), false],
                [__('Total'), static::money($total), true],
            ],
            'grandTotal' => static::money($total),
            'amountInWords' => AmountInWords::format($total),
            'notes' => $record->notes,
            'qr' => null,
            'signatures' => [__('Accountant'), __('Financial Manager')],
            'record' => $record,
        ];
    }
}
