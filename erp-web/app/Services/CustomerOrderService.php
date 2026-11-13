<?php

namespace App\Services;

use App\Enums\SalesDocumentType;
use App\Exceptions\ErpException;
use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;

/**
 * Customer order (طلبات العملاء) approval workflow.
 *
 * draft --post--> confirmed (المرحلة) --approve--> approved (المعتمدة) | partially_approved (المعتمدة جزئيا)
 *                                     --reject---> rejected (المرفوضة)
 */
class CustomerOrderService
{
    /**
     * Approve a posted order. Lines missing from $approvedQuantities are approved in full.
     *
     * @param  array<int|string, float|int|string|null>  $approvedQuantities  line id => approved quantity
     */
    public static function approve(SalesDocument $order, array $approvedQuantities = []): SalesDocument
    {
        static::assertPendingOrder($order);

        return DB::transaction(function () use ($order, $approvedQuantities) {
            $lines = $order->lines()->with('item')->get();
            if ($lines->isEmpty()) {
                throw new ErpException(__('The document has no items.'));
            }

            $isFullyApproved = true;
            $approvedTotal = 0.0;

            foreach ($lines as $line) {
                $ordered = (float) $line->quantity;
                $approved = array_key_exists($line->id, $approvedQuantities) && $approvedQuantities[$line->id] !== null && $approvedQuantities[$line->id] !== ''
                    ? round((float) $approvedQuantities[$line->id], 3)
                    : $ordered;

                if ($approved < 0 || $approved > $ordered + 0.0005) {
                    throw new ErpException(__('The approved quantity of :item must be between 0 and :qty.', ['item' => $line->item?->label, 'qty' => $ordered]));
                }

                $isFullyApproved = $isFullyApproved && $approved + 0.0005 >= $ordered;
                $approvedTotal += $approved;
                $line->forceFill(['approved_qty' => $approved])->saveQuietly();
            }

            if ($approvedTotal <= 0) {
                throw new ErpException(__('Approve at least one quantity, or reject the order.'));
            }

            $order->forceFill([
                'status' => $isFullyApproved ? 'approved' : 'partially_approved',
                'rejection_reason' => null,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ])->save();

            return $order->refresh();
        });
    }

    public static function reject(SalesDocument $order, string $reason): SalesDocument
    {
        static::assertPendingOrder($order);

        if (blank($reason)) {
            throw new ErpException(__('Enter the rejection reason.'));
        }

        $order->lines()->update(['approved_qty' => 0]);
        $order->forceFill([
            'status' => 'rejected',
            'rejection_reason' => trim($reason),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ])->save();

        return $order->refresh();
    }

    /** An order can be approved / rejected after posting and until it is converted to an invoice. */
    public static function canDecide(SalesDocument $order): bool
    {
        return $order->type === SalesDocumentType::CustomerOrder->value
            && $order->is_posted
            && in_array($order->status, ['confirmed', 'partially_approved'], true)
            && $order->convertedDocuments()->doesntExist();
    }

    protected static function assertPendingOrder(SalesDocument $order): void
    {
        if ($order->type !== SalesDocumentType::CustomerOrder->value) {
            throw new ErpException(__('This is not a customer order.'));
        }

        if (! $order->is_posted) {
            throw new ErpException(__('Post the customer order before approving or rejecting it.'));
        }

        if (! static::canDecide($order)) {
            throw new ErpException(__('This customer order was already processed.'));
        }
    }
}
