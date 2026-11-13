<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Exceptions\ErpException;
use App\Models\ChequeCollection;
use App\Models\VoucherLine;
use Illuminate\Support\Collection;

/**
 * Cheque collection documents (تحصيل الشيكات): which cheques are selected, totals, post/unpost.
 * The journal posting itself lives in VoucherService::postCollection().
 */
class ChequeCollectionService
{
    /**
     * Grid rows for the collection form. Posted collections show the cheques they collected;
     * drafts show the cheques under collection due in the period.
     *
     * @param  array<int>|null  $selectedIds  null = select every cheque
     * @return array<int, array{id: int, selected: bool, voucher_no: string, cheque_status: ?string, debit_account: ?string, amount: float, cheque_no: ?string, due_date: ?string, bank: ?string, account_type: ?string, account: ?string, salesman: ?string}>
     */
    public static function rows(?int $branchId, ?string $from, ?string $to, ?array $selectedIds = null, ?ChequeCollection $collection = null): array
    {
        if ($collection?->is_posted) {
            $cheques = $collection->cheques()->with('voucher.account', 'bank', 'account', 'salesman')->orderBy('due_date')->get();
            $selectedIds = null;
        } elseif ($branchId && $from && $to) {
            $cheques = VoucherService::collectableCheques($branchId, $from, $to)->load('voucher.account');
        } else {
            $cheques = new Collection;
        }

        return $cheques->map(fn (VoucherLine $line): array => [
            'id' => $line->id,
            'selected' => $selectedIds === null || in_array($line->id, array_map('intval', $selectedIds), true),
            'voucher_no' => (string) $line->voucher?->number,
            'cheque_status' => AccountType::chequeStatusOptions()[$line->cheque_status] ?? $line->cheque_status,
            'debit_account' => $line->voucher?->account?->label,
            'amount' => round((float) $line->amount, 2),
            'cheque_no' => $line->cheque_no,
            'due_date' => $line->due_date?->format('d/m/Y'),
            'bank' => $line->bank?->name,
            'account_type' => AccountType::labelFor($line->account?->type),
            'account' => $line->account?->label,
            'salesman' => $line->salesman?->name,
        ])->values()->all();
    }

    /**
     * Ids of the ticked rows of the form grid.
     *
     * @param  array<array-key, array{id?: mixed, selected?: mixed}>|null  $rows
     * @return array<int>
     */
    public static function selectedIds(?array $rows): array
    {
        return collect($rows ?? [])
            ->filter(fn (array $row): bool => (bool) ($row['selected'] ?? false) && filled($row['id'] ?? null))
            ->map(fn (array $row): int => (int) $row['id'])
            ->values()
            ->all();
    }

    /** Total of the selected cheques (or the collected ones once posted). */
    public static function recalculate(ChequeCollection $collection): ChequeCollection
    {
        if ($collection->is_posted) {
            return $collection;
        }

        $ids = $collection->cheque_line_ids;
        $cheques = VoucherService::collectableCheques($collection->branch_id, $collection->from_date->toDateString(), $collection->to_date->toDateString());
        if ($ids !== null) {
            $cheques = $cheques->whereIn('id', array_map('intval', $ids));
        }

        $collection->forceFill(['total' => round((float) $cheques->sum('amount'), 2)])->saveQuietly();

        return $collection;
    }

    public static function post(ChequeCollection $collection): ChequeCollection
    {
        $ids = $collection->cheque_line_ids;
        if ($ids !== null && $ids === []) {
            throw new ErpException(__('Select the cheques to collect.'));
        }

        return VoucherService::postCollection($collection, $ids === null ? null : array_map('intval', $ids));
    }

    public static function unpost(ChequeCollection $collection): ChequeCollection
    {
        return VoucherService::unpostCollection($collection);
    }
}
