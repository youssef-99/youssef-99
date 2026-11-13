<?php

namespace App\Services;

use App\Exceptions\ErpException;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Supplier master: sub-ledger account and opening balance.
 *
 * When no account is chosen a dedicated account is created under the payables parent
 * (PartyAccountService). A chosen existing account is used as is (never renamed).
 */
class SupplierService
{
    public static function afterSave(Supplier $supplier): Supplier
    {
        if ((float) $supplier->opening_debit > 0 && (float) $supplier->opening_credit > 0) {
            throw new ErpException(__('Enter either an opening debit or an opening credit balance, not both.'));
        }

        return DB::transaction(function () use ($supplier) {
            if (! $supplier->account_id) {
                PartyAccountService::ensureAccount($supplier);
                $supplier->refresh();
            }

            PartyAccountService::syncOpeningBalance($supplier);

            return $supplier;
        });
    }

    public static function delete(Supplier $supplier): void
    {
        if ($supplier->purchaseDocuments()->exists()) {
            throw new ErpException(__('This supplier has purchase documents and cannot be deleted.'));
        }

        JournalService::removeFor($supplier);
        $supplier->delete();
    }
}
