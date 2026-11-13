<?php

namespace App\Services;

use App\Enums\VoucherType;
use App\Exceptions\ErpException;
use App\Models\ChequeCollection;
use App\Models\Voucher;
use App\Models\VoucherLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receipt / payment vouchers, credit / debit notices, cheque collection.
 */
class VoucherService
{
    public static function numberKey(string $type): string
    {
        return "voucher.{$type}";
    }

    public static function initialize(Voucher $voucher): void
    {
        $voucher->fiscal_year ??= (int) Carbon::parse($voucher->date ?? now())->format('Y');
        $voucher->created_by ??= auth()->id();

        if ($voucher->type === VoucherType::ReceiptCheque->value) {
            $voucher->cheque_status ??= 'under_collection';
            $voucher->account_id ??= Settings::account('cheques_under_collection');
        }

        if (blank($voucher->number)) {
            $voucher->number = DocumentNumber::next(static::numberKey($voucher->type), $voucher->branch_id, $voucher->fiscal_year);
        } else {
            DocumentNumber::bump(static::numberKey($voucher->type), $voucher->branch_id, $voucher->fiscal_year, (string) $voucher->number);
        }
    }

    /** Header amount = sum of lines. */
    public static function recalculate(Voucher $voucher): Voucher
    {
        $voucher->loadMissing('lines');
        $voucher->amount = round((float) $voucher->lines->sum('amount'), 2);

        if ($voucher->type === VoucherType::ReceiptCheque->value) {
            $voucher->lines->whereNull('cheque_status')->each(fn (VoucherLine $l) => $l->forceFill(['cheque_status' => 'under_collection'])->saveQuietly());
        }

        $voucher->saveQuietly();

        return $voucher;
    }

    public static function post(Voucher $voucher): Voucher
    {
        if ($voucher->is_posted) {
            throw new ErpException(__('This document is already posted.'));
        }

        $type = VoucherType::from($voucher->type);

        return DB::transaction(function () use ($voucher, $type) {
            static::recalculate($voucher);

            if ($voucher->lines->isEmpty() || (float) $voucher->amount <= 0) {
                throw new ErpException(__('The voucher has no lines.'));
            }

            $sign = $type->headerIsDebit() ? 1 : -1;
            $desc = $voucher->description ?: $type->label().' #'.$voucher->number;
            $lines = [SalesDocumentService::side($voucher->account_id, $sign * (float) $voucher->amount, $desc)];

            foreach ($voucher->lines as $line) {
                $lines[] = [
                    'account_id' => $line->account_id,
                    'debit' => $sign < 0 ? (float) $line->amount : 0,
                    'credit' => $sign > 0 ? (float) $line->amount : 0,
                    'description' => $line->description ?: $desc,
                    'salesman_id' => $line->salesman_id,
                    'cost_center_id' => $line->cost_center_id,
                    'accreditation_id' => $line->accreditation_id,
                    'document_no' => $line->cheque_no ?: $voucher->manual_document_no,
                    'document_date' => $line->due_date,
                ];
            }

            $entry = JournalService::record($voucher, $type->entryType(), $voucher->branch_id, $voucher->date, $lines, $desc);
            $voucher->forceFill(['is_posted' => true, 'posted_at' => now(), 'journal_entry_id' => $entry->id])->saveQuietly();

            return $voucher;
        });
    }

    public static function unpost(Voucher $voucher): Voucher
    {
        if ($voucher->lines()->whereNotNull('cheque_collection_id')->exists()) {
            throw new ErpException(__('Some cheques in this voucher were already collected.'));
        }

        JournalService::removeFor($voucher);
        $voucher->forceFill(['is_posted' => false, 'posted_at' => null, 'journal_entry_id' => null])->saveQuietly();

        return $voucher;
    }

    /** Cheques under collection, due in the collection period, for the branch. */
    public static function collectableCheques(int $branchId, string $from, string $to)
    {
        return VoucherLine::query()
            ->with('voucher', 'bank', 'account', 'salesman')
            ->whereHas('voucher', fn ($q) => $q->where('type', VoucherType::ReceiptCheque->value)->where('branch_id', $branchId)->where('is_posted', true))
            ->where('cheque_status', 'under_collection')
            ->whereNull('cheque_collection_id')
            ->whereBetween('due_date', [$from, $to])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Post a cheque collection: Dr bank account, Cr cheques-under-collection (each voucher's header account).
     *
     * @param  array<int>|null  $lineIds  limit to these cheques (default: all collectable in the period)
     */
    public static function postCollection(ChequeCollection $collection, ?array $lineIds = null): ChequeCollection
    {
        if ($collection->is_posted) {
            throw new ErpException(__('This document is already posted.'));
        }

        return DB::transaction(function () use ($collection, $lineIds) {
            $cheques = static::collectableCheques($collection->branch_id, $collection->from_date->toDateString(), $collection->to_date->toDateString());
            if ($lineIds !== null) {
                $cheques = $cheques->whereIn('id', $lineIds);
            }
            if ($cheques->isEmpty()) {
                throw new ErpException(__('There are no cheques to collect in this period.'));
            }

            $bank = $collection->bank_account_id ?? throw new ErpException(__('Select the bank account.'));
            $total = round((float) $cheques->sum('amount'), 2);
            $desc = __('Cheque Collection').' #'.$collection->number;
            $lines = [SalesDocumentService::side($bank, $total, $desc)];
            foreach ($cheques as $cheque) {
                $lines[] = SalesDocumentService::side($cheque->voucher->account_id, -(float) $cheque->amount, __('Cheque No.').' '.$cheque->cheque_no) + ['document_no' => $cheque->cheque_no];
            }

            $entry = JournalService::record($collection, 'cheque_collection', $collection->branch_id, $collection->date, $lines, $desc);
            VoucherLine::query()->whereIn('id', $cheques->pluck('id'))->update(['cheque_status' => 'collected', 'cheque_collection_id' => $collection->id]);
            $collection->forceFill(['total' => $total, 'is_posted' => true, 'posted_at' => now(), 'journal_entry_id' => $entry->id])->saveQuietly();

            return $collection;
        });
    }
}
