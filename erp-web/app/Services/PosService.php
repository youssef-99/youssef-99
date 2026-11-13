<?php

namespace App\Services;

use App\Enums\SalesDocumentType;
use App\Exceptions\ErpException;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use Illuminate\Support\Facades\DB;

/**
 * POS invoices and returns (نقاط البيع).
 *
 * Wraps SalesDocumentService with the POS rules:
 *  - promotions applied to the lines on every recalculation (drafts),
 *  - payment: cash + card must cover the total; card may not exceed it; cash may be over-tendered
 *    and change due = amount received − cash due (cash due = total − card),
 *  - returns limited to the quantity sold minus earlier returns, refunded by the original payment method,
 *  - loyalty points earned / redeemed (LoyaltyPointsService); ZATCA is prepared by the posting engine.
 */
class PosService
{
    public static function recalculate(SalesDocument $doc): SalesDocument
    {
        $isReturn = $doc->type === SalesDocumentType::PosReturn->value;

        if (! $doc->is_posted) {
            if ($isReturn) {
                static::copyOriginalPrices($doc);
            } else {
                PromotionService::applyToDocument($doc);
            }

            $doc->points_discount = LoyaltyPointsService::discountFor((float) $doc->points_redeemed);
        }

        // The posting engine treats cash + card as "received"; POS "Amount Received" is the cash tendered.
        $received = (float) $doc->amount_received;
        SalesDocumentService::recalculate($doc);
        $doc->amount_received = $received;

        if ($isReturn) {
            static::applyRefund($doc);
        } else {
            static::applyPayment($doc, strict: false);
        }

        $doc->saveQuietly();

        return $doc;
    }

    public static function post(SalesDocument $doc): SalesDocument
    {
        if ($doc->is_posted) {
            throw new ErpException(__('This document is already posted.'));
        }

        $doc = DB::transaction(function () use ($doc) {
            static::recalculate($doc);
            $isReturn = $doc->type === SalesDocumentType::PosReturn->value;

            if ($isReturn) {
                static::assertReturnable($doc);
            } else {
                static::applyPayment($doc, strict: true);
                $doc->load('pointsCustomer');
                LoyaltyPointsService::assertCanRedeem($doc);
            }

            $payment = $doc->only(['cash_amount', 'card_amount', 'amount_received', 'change_due', 'is_paid', 'payment_method']);
            $doc->saveQuietly();

            SalesDocumentService::post($doc);

            $doc->forceFill($payment)->saveQuietly();

            if (! $isReturn) {
                LoyaltyPointsService::redeem($doc);
            }

            return $doc->refresh();
        });

        // SalesDocumentService::post() earns loyalty points and submits to ZATCA (on_post mode).
        return $doc;
    }

    public static function unpost(SalesDocument $doc): SalesDocument
    {
        if ($doc->type === SalesDocumentType::PosInvoice->value
            && $doc->returns()->where('is_posted', true)->exists()) {
            throw new ErpException(__('This invoice has posted returns. Unpost the returns first.'));
        }

        return DB::transaction(function () use ($doc) {
            SalesDocumentService::unpost($doc);

            return $doc->refresh();
        });
    }

    /**
     * Payment split for a POS invoice.
     *
     * @return array{card_amount: float, cash_due: float, amount_received: float, change_due: float, is_paid: bool, payment_method: string, short: float}
     */
    public static function payment(float $total, float $cash, float $card, float $received = 0): array
    {
        $total = round($total, 2);
        $card = round(max(0, $card), 2);
        $cashDue = round(max(0, $total - $card), 2);
        $tendered = round(max($cash, $received), 2);
        $short = round(max(0, $cashDue - $tendered), 2);

        return [
            'card_amount' => $card,
            'cash_due' => $cashDue,
            'amount_received' => $tendered,
            'change_due' => round(max(0, $tendered - $cashDue), 2),
            'is_paid' => $short <= 0.0 && $card <= $total,
            'payment_method' => match (true) {
                $card <= 0 => 'cash',
                $cashDue <= 0 => 'card',
                default => 'mixed',
            },
            'short' => $short,
        ];
    }

    protected static function applyPayment(SalesDocument $doc, bool $strict): void
    {
        $total = (float) $doc->grand_total;
        $p = static::payment($total, (float) $doc->cash_amount, (float) $doc->card_amount, (float) $doc->amount_received);

        if ($strict) {
            if ($p['card_amount'] > round($total, 2)) {
                throw new ErpException(__('The Visa / Mada amount cannot exceed the invoice total.'));
            }
            if ($p['short'] > 0) {
                throw new ErpException(__('The payment does not cover the invoice total. Remaining: :amount', ['amount' => number_format($p['short'], 2)]));
            }
        }

        $doc->fill([
            'amount_received' => $p['amount_received'],
            'change_due' => $p['change_due'],
            'is_paid' => $p['is_paid'],
            'payment_method' => $p['payment_method'],
        ]);
    }

    /** Refund a return by the payment method of the original invoice. */
    protected static function applyRefund(SalesDocument $doc): void
    {
        $original = $doc->originalDocument;
        $total = round((float) $doc->grand_total, 2);
        $card = $original ? min($total, round((float) $original->card_amount, 2)) : 0.0;

        $doc->fill([
            'card_amount' => $card,
            'cash_amount' => round($total - $card, 2),
            'amount_received' => round($total - $card, 2),
            'change_due' => 0,
            'is_paid' => true,
            'payment_method' => $original?->payment_method ?? 'cash',
            'points_customer_id' => $doc->points_customer_id ?? $original?->points_customer_id,
            'doctor_id' => $doc->doctor_id ?? $original?->doctor_id,
        ]);
    }

    /** Return lines always use the price, discount and VAT of the original invoice line. */
    protected static function copyOriginalPrices(SalesDocument $doc): void
    {
        $original = $doc->originalDocument()->with('lines')->first();
        if (! $original) {
            return;
        }

        foreach ($doc->lines()->get() as $line) {
            $source = static::matchLine($original->lines, $line);
            if ($source) {
                $line->fill($source->only(['unit_price', 'discount_percent', 'tax_percent', 'unit_id']));
                if ($line->isDirty()) {
                    $line->save();
                }
            }
        }

        $base = (float) $original->net_total + (float) $original->additional_discount;
        $doc->additional_discount_percent = $base > 0 ? round((float) $original->additional_discount / $base * 100, 4) : 0;
        $doc->additional_discount = 0;
        $doc->unsetRelation('lines');
    }

    /**
     * Quantities sold on the original invoice and already returned by other posted returns,
     * keyed by "item_id|batch_id".
     *
     * @return array<string, array{item_id: int, item_batch_id: ?int, sold: float, returned: float, remaining: float}>
     */
    public static function returnableQuantities(SalesDocument $original, ?int $exceptReturnId = null): array
    {
        $rows = [];
        foreach ($original->lines()->get() as $line) {
            $key = static::lineKey($line->item_id, $line->item_batch_id);
            $rows[$key] ??= ['item_id' => (int) $line->item_id, 'item_batch_id' => $line->item_batch_id, 'sold' => 0.0, 'returned' => 0.0, 'remaining' => 0.0];
            $rows[$key]['sold'] += (float) $line->quantity;
        }

        $returned = SalesDocumentLine::query()
            ->whereHas('salesDocument', fn ($q) => $q
                ->where('original_document_id', $original->id)
                ->where('type', SalesDocumentType::PosReturn->value)
                ->where('is_posted', true)
                ->when($exceptReturnId, fn ($q, $id) => $q->whereKeyNot($id)))
            ->get(['item_id', 'item_batch_id', 'quantity']);

        foreach ($returned as $line) {
            $key = static::lineKey($line->item_id, $line->item_batch_id);
            if (isset($rows[$key])) {
                $rows[$key]['returned'] += (float) $line->quantity;
            }
        }

        foreach ($rows as $key => $row) {
            $rows[$key]['remaining'] = round(max(0, $row['sold'] - $row['returned']), 3);
        }

        return $rows;
    }

    public static function lineKey(int|string|null $itemId, int|string|null $batchId): string
    {
        return (int) $itemId.'|'.($batchId ? (int) $batchId : '');
    }

    protected static function assertReturnable(SalesDocument $doc): void
    {
        $original = $doc->originalDocument;
        if (! $original || $original->type !== SalesDocumentType::PosInvoice->value || ! $original->is_posted) {
            throw new ErpException(__('Select a posted POS invoice to return.'));
        }

        $available = static::returnableQuantities($original, $doc->id);
        $requested = [];
        foreach ($doc->lines as $line) {
            $key = static::lineKey($line->item_id, $line->item_batch_id);
            $requested[$key] = ($requested[$key] ?? 0) + (float) $line->quantity;
        }

        foreach ($requested as $key => $qty) {
            $remaining = $available[$key]['remaining'] ?? 0.0;
            if ($qty > $remaining + 0.0005) {
                $item = $doc->lines->first(fn ($l) => static::lineKey($l->item_id, $l->item_batch_id) === $key)?->item;
                throw new ErpException(__('Return quantity for :item exceeds the quantity sold (remaining :remaining).', [
                    'item' => $item?->name ?? $key,
                    'remaining' => (float) $remaining,
                ]));
            }
        }
    }

    /** @param  iterable<SalesDocumentLine>  $lines */
    protected static function matchLine(iterable $lines, SalesDocumentLine $line): ?SalesDocumentLine
    {
        $fallback = null;
        foreach ($lines as $candidate) {
            if ((int) $candidate->item_id !== (int) $line->item_id) {
                continue;
            }
            if ((int) $candidate->item_batch_id === (int) $line->item_batch_id) {
                return $candidate;
            }
            $fallback ??= $candidate;
        }

        return $fallback;
    }
}
