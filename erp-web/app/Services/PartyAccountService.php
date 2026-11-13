<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

/**
 * Every customer / supplier gets its own sub-ledger account under a parent
 * (receivables / payables) and an opening-balance journal entry.
 */
class PartyAccountService
{
    public static function ensureAccount(Customer|Supplier $party): Account
    {
        if ($party->account_id && ($account = Account::query()->find($party->account_id))) {
            $account->update(['name_ar' => $party->name_ar, 'name_en' => $party->name_en]);

            return $account;
        }

        $isCustomer = $party instanceof Customer;
        $parentId = ($isCustomer ? $party->parent_account_id : null) ?: Settings::accountOrFail($isCustomer ? 'receivables' : 'payables');
        $parent = Account::query()->findOrFail($parentId);

        $account = Account::query()->create([
            'parent_id' => $parent->id,
            'number' => static::nextChildNumber($parent),
            'level' => $parent->level + 1,
            'name_ar' => $party->name_ar,
            'name_en' => $party->name_en,
            'nature' => $isCustomer ? 'debit' : 'credit',
            'type' => $isCustomer ? 'customer' : 'supplier',
            'balance_sheet_item' => $parent->balance_sheet_item,
            'is_postable' => true,
        ]);

        $party->forceFill(['account_id' => $account->id] + ($isCustomer ? ['parent_account_id' => $parent->id] : []))->saveQuietly();

        return $account;
    }

    public static function nextChildNumber(Account $parent): string
    {
        $last = Account::query()->where('parent_id', $parent->id)->pluck('number')
            ->filter(fn ($n) => ctype_digit((string) $n))->map(fn ($n) => (int) $n)->max();

        if ($last) {
            return (string) ($last + 1);
        }

        $width = in_array($parent->level, [1, 2], true) ? 2 : 3;

        return $parent->number.str_pad('1', $width, '0', STR_PAD_LEFT);
    }

    /** Create / refresh the opening-balance entry for a party. */
    public static function syncOpeningBalance(Customer|Supplier $party, ?int $branchId = null): void
    {
        $debit = round((float) $party->opening_debit, 2);
        $credit = round((float) $party->opening_credit, 2);

        if ($debit == 0.0 && $credit == 0.0) {
            JournalService::removeFor($party);

            return;
        }

        $offset = Settings::accountOrFail('opening_balances');
        $amount = $debit - $credit;
        $branchId ??= auth()->user()?->default_branch_id ?? \App\Models\Branch::query()->value('id');
        $date = $party->account_opening_date ?? now()->startOfYear();

        JournalService::record($party, 'opening', $branchId, $date, [
            SalesDocumentService::side($party->account_id, $amount, __('Opening Balance')),
            SalesDocumentService::side($offset, -$amount, __('Opening Balance')),
        ], __('Opening Balance').' - '.$party->name);
    }

    public static function sync(Model $party): void
    {
        static::ensureAccount($party);
        static::syncOpeningBalance($party->refresh());
    }
}
