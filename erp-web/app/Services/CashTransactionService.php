<?php

namespace App\Services;

use App\Models\PurchaseDocument;
use App\Models\SalesDocument;

/**
 * Cash Transactions helpers: a return created from the modal header against an original
 * invoice starts with the original's (still returnable) lines.
 */
class CashTransactionService
{
    public static function copyOriginalLines(PurchaseDocument|SalesDocument $return): void
    {
        if (! $return->original_document_id || $return->lines()->exists()) {
            return;
        }

        $original = $return->originalDocument()->with('lines')->first();
        if (! $original) {
            return;
        }

        if ($return instanceof PurchaseDocument) {
            $rows = PurchaseDocumentService::returnLinesFor($original);
            foreach ($rows as $row) {
                $return->lines()->create($row);
            }
            $return->forceFill(['additional_discount' => PurchaseDocumentService::returnAdditionalDiscount($original, $rows)])->saveQuietly();
            PurchaseDocumentService::recalculate($return->refresh());

            return;
        }

        foreach ($original->lines as $line) {
            $return->lines()->create($line->only(['item_id', 'unit_id', 'item_batch_id', 'quantity', 'unit_price', 'discount_percent', 'tax_percent']));
        }
        $return->forceFill(['additional_discount' => $original->additional_discount, 'additional_discount_percent' => $original->additional_discount_percent, 'cash_customer_id' => $return->cash_customer_id ?? $original->cash_customer_id])->saveQuietly();
        SalesDocumentService::recalculate($return->refresh());
    }
}
