<?php

namespace App\Services;

use App\Enums\PurchaseDocumentType;
use App\Exceptions\ErpException;
use App\Models\ItemSerialNumber;
use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentLine;
use App\Models\Warehouse;
use App\Services\Calc\DocumentCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Totals and posting for purchase orders, invoices, returns, foreign and cash purchases.
 *
 * Invoice posting:  Dr Inventory (net + allocated expenses), Dr Input VAT, Cr Supplier (or L/C or cash).
 *                   Shipping cost and purchase expenses are capitalised into inventory and credited to the
 *                   L/C account (when linked) or the foreign purchase expenses clearing account.
 *                   Each line's unit cost = (line taxable after all discounts + expense share) / (qty + bonus).
 * Return posting:   the reverse, stock out. A return against an invoice cannot exceed the received quantity.
 */
class PurchaseDocumentService
{
    public static function numberKey(PurchaseDocumentType|string $type): string
    {
        return 'purchase.'.($type instanceof PurchaseDocumentType ? $type->value : $type);
    }

    public static function initialize(PurchaseDocument $doc): void
    {
        $doc->fiscal_year ??= (int) Carbon::parse($doc->date ?? now())->format('Y');
        $doc->warehouse_id ??= Warehouse::query()->where('branch_id', $doc->branch_id)->value('id');
        $doc->created_by ??= auth()->id();

        if ($doc->payment_period_days && ! $doc->due_date) {
            $doc->due_date = Carbon::parse($doc->date)->addDays((int) $doc->payment_period_days);
        }

        if (blank($doc->number)) {
            $doc->number = DocumentNumber::next(static::numberKey($doc->type), $doc->branch_id, $doc->fiscal_year);
        } else {
            DocumentNumber::bump(static::numberKey($doc->type), $doc->branch_id, $doc->fiscal_year, (string) $doc->number);
        }
    }

    public static function recalculate(PurchaseDocument $doc): PurchaseDocument
    {
        $doc->loadMissing('lines');
        $lines = $doc->lines->mapWithKeys(fn ($l) => [$l->id => $l->only([
            'quantity', 'unit_price', 'discount', 'discount_percent', 'discount2', 'discount2_percent', 'bonus_qty', 'bonus_percent', 'tax_percent',
        ])])->all();
        $totals = DocumentCalculator::totals($lines, $doc->additional_discount, 0);

        foreach ($doc->lines as $line) {
            $c = $totals['lines'][$line->id];
            $line->fill([
                'gross' => $c['gross'], 'discount' => $c['discount'], 'discount2' => $c['discount2'],
                'bonus_qty' => $c['bonus_qty'], 'tax' => $c['tax'], 'net' => $c['net'], 'unit_net' => $c['unit_net'],
            ]);
            if ($line->isDirty()) {
                $line->save();
            }
        }

        $doc->fill([
            'gross_total' => $totals['gross_total'],
            'items_discount' => $totals['items_discount'],
            'additional_discount' => $totals['additional_discount'],
            'net_total' => $totals['net_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
        ])->saveQuietly();

        return $doc;
    }

    public static function post(PurchaseDocument $doc): PurchaseDocument
    {
        $type = PurchaseDocumentType::from($doc->type);

        if ($doc->is_posted) {
            throw new ErpException(__('This document is already posted.'));
        }

        if (! $type->isPostable()) {
            $doc->update(['status' => 'approved']);

            return $doc;
        }

        return DB::transaction(function () use ($doc, $type) {
            static::recalculate($doc);
            $doc->load('lines.item', 'supplier', 'accreditation');

            if ($doc->lines->isEmpty()) {
                throw new ErpException(__('The document has no items.'));
            }

            if ($type->isReturn()) {
                static::assertReturnable($doc);
            }

            $warehouseId = $doc->warehouse_id ?? throw new ErpException(__('Select a warehouse.'));
            $rate = (float) ($doc->exchange_rate ?: 1);
            $extra = ((float) $doc->shipping_cost + (float) $doc->purchase_expenses) * $rate;
            $taxableSum = max(0.01, (float) $doc->lines->sum(fn ($l) => (float) $l->gross - (float) $l->discount - (float) $l->discount2));
            $docDiscountFactor = (float) $doc->net_total / $taxableSum;
            $direction = $type->stockDirection();

            foreach ($doc->lines as $line) {
                $item = $line->item;
                $batch = $item->isBatchTracked() || $line->batch_no || $line->expiry_date
                    ? StockService::resolveBatch($item, $line->batch_no, $line->expiry_date)
                    : null;
                $line->item_batch_id = $batch?->id;
                $line->saveQuietly();

                $lineTaxable = (float) $line->gross - (float) $line->discount - (float) $line->discount2;
                $share = $lineTaxable / $taxableSum;
                $lineCostLocal = $lineTaxable * $docDiscountFactor * $rate + $extra * $share;
                $qty = (float) $line->quantity + (float) $line->bonus_qty;
                $unitCost = $qty > 0 ? $lineCostLocal / $qty : 0;

                StockService::move($doc, $type->value, $item, $doc->branch_id, $warehouseId, $doc->date, $direction * $qty, $unitCost, $batch?->id, allowNegative: (bool) config('erp.allow_negative_stock', true));

                if (! $type->isReturn()) {
                    $item->forceFill(['purchase_price' => round((float) $line->unit_price * $rate, 4)] + ($line->sale_price ? ['sale_price' => $line->sale_price] : []))->saveQuietly();
                    $batch?->forceFill(['last_purchase_price' => round($unitCost, 4)] + ($line->sale_price ? ['sale_price' => $line->sale_price] : []))->saveQuietly();
                }

                static::syncSerialNumbers($doc, $line, $type, $warehouseId);
            }

            $entry = JournalService::record($doc, $type->isReturn() ? 'purchase_return' : 'purchase', $doc->branch_id, $doc->date, static::journalLines($doc, $type), $type->label().' #'.$doc->number);

            $doc->forceFill(['is_posted' => true, 'posted_at' => now(), 'status' => 'approved', 'journal_entry_id' => $entry->id])->saveQuietly();

            return $doc->refresh();
        });
    }

    public static function unpost(PurchaseDocument $doc): PurchaseDocument
    {
        $type = PurchaseDocumentType::from($doc->type);

        if (! $type->isReturn() && $doc->returns()->where('is_posted', true)->exists()) {
            throw new ErpException(__('This invoice has posted returns. Unpost the returns first.'));
        }

        return DB::transaction(function () use ($doc, $type) {
            StockService::removeFor($doc);
            JournalService::removeFor($doc);
            static::releaseSerialNumbers($doc, $type);
            $doc->forceFill(['is_posted' => false, 'posted_at' => null, 'journal_entry_id' => null, 'status' => 'draft'])->saveQuietly();

            return $doc;
        });
    }

    /**
     * Quantities (qty + bonus) per item still returnable from an invoice, excluding $exceptReturnId.
     *
     * @return array<int, float> item_id => quantity
     */
    public static function returnableQuantities(PurchaseDocument $invoice, ?int $exceptReturnId = null): array
    {
        $received = [];
        foreach ($invoice->lines()->get() as $line) {
            $received[$line->item_id] = ($received[$line->item_id] ?? 0) + (float) $line->quantity + (float) $line->bonus_qty;
        }

        $returned = PurchaseDocumentLine::query()
            ->whereHas('purchaseDocument', fn ($q) => $q->where('original_document_id', $invoice->id)->where('is_posted', true)
                ->when($exceptReturnId, fn ($q, $id) => $q->whereKeyNot($id)))
            ->get()
            ->groupBy('item_id')
            ->map(fn ($lines) => $lines->sum(fn ($l) => (float) $l->quantity + (float) $l->bonus_qty));

        foreach ($received as $itemId => $qty) {
            $received[$itemId] = round($qty - (float) ($returned[$itemId] ?? 0), 3);
        }

        return $received;
    }

    /** A return against an invoice cannot return items that were not received, or more than received. */
    public static function assertReturnable(PurchaseDocument $doc): void
    {
        if (! $doc->original_document_id) {
            return;
        }

        $invoice = PurchaseDocument::query()->findOrFail($doc->original_document_id);
        if (! $invoice->is_posted) {
            throw new ErpException(__('The original invoice is not posted.'));
        }

        $available = static::returnableQuantities($invoice, $doc->id);
        $requested = [];
        foreach ($doc->lines as $line) {
            $requested[$line->item_id] = ($requested[$line->item_id] ?? 0) + (float) $line->quantity + (float) $line->bonus_qty;
        }

        foreach ($requested as $itemId => $qty) {
            $max = $available[$itemId] ?? null;
            if ($max === null || $qty > $max + 0.0001) {
                $item = $doc->lines->firstWhere('item_id', $itemId)?->item;
                throw new ErpException(__('Cannot return more than received for :item: returnable :available, requested :requested.', [
                    'item' => $item?->label ?? $itemId, 'available' => max(0, (float) $max), 'requested' => $qty,
                ]));
            }
        }
    }

    /**
     * Convert a purchase order into a draft purchase invoice (lines copied) and close the order.
     */
    public static function convertOrderToInvoice(PurchaseDocument $order): PurchaseDocument
    {
        if ($order->type !== PurchaseDocumentType::PurchaseOrder->value) {
            throw new ErpException(__('Only purchase orders can be converted.'));
        }

        if ($order->status === 'closed') {
            throw new ErpException(__('This purchase order was already converted.'));
        }

        $order->loadMissing('lines', 'supplier');
        if ($order->lines->isEmpty()) {
            throw new ErpException(__('The document has no items.'));
        }

        return DB::transaction(function () use ($order) {
            $paymentDays = (int) ($order->supplier?->payment_term_days ?? 0);
            $invoice = PurchaseDocument::query()->create([
                'type' => PurchaseDocumentType::PurchaseInvoice->value,
                'branch_id' => $order->branch_id,
                'date' => now()->toDateString(),
                'receipt_date' => now()->toDateString(),
                'warehouse_id' => $order->warehouse_id,
                'supplier_id' => $order->supplier_id,
                'account_id' => $order->account_id,
                'payment_period_days' => $paymentDays,
                'purchase_order_id' => $order->id,
                'notes' => $order->notes,
                'additional_discount' => $order->additional_discount,
            ]);

            foreach ($order->lines as $line) {
                $invoice->lines()->create($line->only([
                    'item_id', 'unit_id', 'batch_no', 'expiry_date', 'quantity', 'unit_price', 'discount_percent',
                    'discount2_percent', 'bonus_qty', 'bonus_percent', 'tax_percent', 'sale_price',
                ]));
            }

            static::recalculate($invoice);
            $order->forceFill(['status' => 'closed'])->saveQuietly();

            return $invoice->refresh();
        });
    }

    /** Register serial numbers received (invoice) or mark them returned (return). */
    protected static function syncSerialNumbers(PurchaseDocument $doc, PurchaseDocumentLine $line, PurchaseDocumentType $type, int $warehouseId): void
    {
        $serials = array_values(array_filter(array_map('trim', (array) ($line->serial_numbers ?? []))));
        if ($serials === []) {
            return;
        }

        if ($type->isReturn()) {
            ItemSerialNumber::query()->where('item_id', $line->item_id)->whereIn('serial_no', $serials)->update(['status' => 'returned']);

            return;
        }

        foreach ($serials as $serial) {
            $record = ItemSerialNumber::query()->firstOrNew(['item_id' => $line->item_id, 'serial_no' => $serial]);
            $record->fill(['item_batch_id' => $line->item_batch_id, 'warehouse_id' => $warehouseId, 'status' => 'in_stock']);
            $record->source()->associate($doc);
            $record->save();
        }
    }

    protected static function releaseSerialNumbers(PurchaseDocument $doc, PurchaseDocumentType $type): void
    {
        if ($type->isReturn()) {
            foreach ($doc->lines()->get() as $line) {
                $serials = array_values(array_filter((array) ($line->serial_numbers ?? [])));
                if ($serials) {
                    ItemSerialNumber::query()->where('item_id', $line->item_id)->whereIn('serial_no', $serials)->where('status', 'returned')->update(['status' => 'in_stock']);
                }
            }

            return;
        }

        ItemSerialNumber::query()
            ->where('source_type', $doc->getMorphClass())
            ->where('source_id', $doc->getKey())
            ->where('status', 'in_stock')
            ->delete();
    }

    /** @return array<int, array> */
    protected static function journalLines(PurchaseDocument $doc, PurchaseDocumentType $type): array
    {
        $sign = $type->isReturn() ? -1 : 1;
        $rate = (float) ($doc->exchange_rate ?: 1);
        $desc = $type->label().' #'.$doc->number.($doc->supplier_invoice_no ? ' / '.$doc->supplier_invoice_no : '');
        $extra = round(((float) $doc->shipping_cost + (float) $doc->purchase_expenses) * $rate, 2);
        $net = round((float) $doc->net_total * $rate, 2);
        $tax = round((float) $doc->tax_total * $rate, 2);
        $lines = [];
        $lcAccount = $doc->accreditation_id ? $doc->accreditation?->lc_account_id : null;

        $inventory = $doc->accreditation?->inventory_account_id ?: Settings::accountOrFail('inventory');
        $lines[] = SalesDocumentService::side($inventory, $sign * ($net + $extra), $desc);

        if ($tax > 0) {
            $lines[] = SalesDocumentService::side(Settings::accountOrFail('vat_input'), $sign * $tax, $desc);
        }

        $payable = $lcAccount ?: ($doc->account_id ?: $doc->supplier?->account_id ?: ($type->isCash() ? Settings::account('cash') : null));

        if (! $payable) {
            throw new ErpException(__('The supplier has no account.'));
        }

        $lines[] = SalesDocumentService::side($payable, -$sign * ($net + $tax), $desc);

        if ($extra > 0) {
            $expensesAccount = $lcAccount ?: (Settings::account('foreign_purchase_expenses') ?? $payable);
            $lines[] = SalesDocumentService::side($expensesAccount, -$sign * $extra, $desc);
        }

        return $lines;
    }
}
