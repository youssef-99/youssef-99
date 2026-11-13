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
            PostingLock::lock($doc, expectPosted: false);
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
            $direction = $type->stockDirection();
            $landed = static::landedLineCosts($doc);
            $original = $type->isReturn() && $doc->original_document_id
                ? PurchaseDocument::query()->with('lines')->find($doc->original_document_id)
                : null;
            $originalCosts = $original ? static::landedLineCosts($original) : [];
            $values = ['stock' => 0.0, 'service' => 0.0];

            foreach ($doc->lines as $line) {
                $item = $line->item;
                $batch = $item->isBatchTracked() || $line->batch_no || $line->expiry_date
                    ? StockService::resolveBatch($item, $line->batch_no, $line->expiry_date)
                    : null;
                $line->item_batch_id = $batch?->id;
                $line->saveQuietly();

                $qty = (float) $line->quantity + (float) $line->bonus_qty;
                $unitCost = $landed[$line->id]['unit_cost'];

                if ($item->isService()) {
                    $values['service'] += $landed[$line->id]['cost'];
                } elseif ($type->isReturn()) {
                    // Stock leaves at what it cost when it came in (expense share and bonus included).
                    $unitCost = ($original ? static::originalLandedUnitCost($original, $originalCosts, $line) : 0.0)
                        ?: StockService::unitCost($item);
                    $values['stock'] += $qty * $unitCost;
                } else {
                    $values['stock'] += $landed[$line->id]['cost'];
                }

                StockService::move($doc, $type->value, $item, $doc->branch_id, $warehouseId, $doc->date, $direction * $qty, $unitCost, $batch?->id, allowNegative: (bool) config('erp.allow_negative_stock', true));

                if (! $type->isReturn()) {
                    $item->forceFill(['purchase_price' => round((float) $line->unit_price * $rate, 4)] + ($line->sale_price ? ['sale_price' => $line->sale_price] : []))->saveQuietly();
                    $batch?->forceFill(['last_purchase_price' => round($unitCost, 4)] + ($line->sale_price ? ['sale_price' => $line->sale_price] : []))->saveQuietly();
                }

                static::syncSerialNumbers($doc, $line, $type, $warehouseId);
            }

            $entry = JournalService::record($doc, $type->isReturn() ? 'purchase_return' : 'purchase', $doc->branch_id, $doc->date, static::journalLines($doc, $type, $values), $type->label().' #'.$doc->number);

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
            PostingLock::lock($doc, expectPosted: true);
            StockService::removeFor($doc);
            JournalService::removeFor($doc);
            static::releaseSerialNumbers($doc, $type);
            $doc->forceFill(['is_posted' => false, 'posted_at' => null, 'journal_entry_id' => null, 'status' => 'draft'])->saveQuietly();

            return $doc;
        });
    }

    /**
     * Landed cost of each line in local currency: line taxable after all discounts (including the
     * document's additional discount) plus its share of shipping and purchase expenses, and the unit
     * cost = that cost / (quantity + bonus).
     *
     * @return array<int, array{cost: float, unit_cost: float}> keyed by line id
     */
    public static function landedLineCosts(PurchaseDocument $doc): array
    {
        $rate = (float) ($doc->exchange_rate ?: 1);
        $extra = ((float) $doc->shipping_cost + (float) $doc->purchase_expenses) * $rate;
        $taxableSum = max(0.01, (float) $doc->lines->sum(fn ($l) => (float) $l->gross - (float) $l->discount - (float) $l->discount2));
        $docDiscountFactor = (float) $doc->net_total / $taxableSum;

        $result = [];
        foreach ($doc->lines as $line) {
            $lineTaxable = (float) $line->gross - (float) $line->discount - (float) $line->discount2;
            $cost = $lineTaxable * $docDiscountFactor * $rate + $extra * ($lineTaxable / $taxableSum);
            $qty = (float) $line->quantity + (float) $line->bonus_qty;
            $result[$line->id] = ['cost' => $cost, 'unit_cost' => $qty > 0 ? $cost / $qty : 0.0];
        }

        return $result;
    }

    /**
     * Landed unit cost of the original invoice line a return line gives back: the line with the same
     * item and batch, else the quantity-weighted cost of the item's lines.
     *
     * @param  array<int, array{cost: float, unit_cost: float}>  $originalCosts
     */
    protected static function originalLandedUnitCost(PurchaseDocument $original, array $originalCosts, PurchaseDocumentLine $line): float
    {
        $quantity = $value = 0.0;
        foreach ($original->lines as $candidate) {
            if ((int) $candidate->item_id !== (int) $line->item_id) {
                continue;
            }
            $sameBatch = $line->item_batch_id
                ? (int) $candidate->item_batch_id === (int) $line->item_batch_id
                : filled($line->batch_no) && $candidate->batch_no === $line->batch_no;
            if ($sameBatch && ($originalCosts[$candidate->id]['unit_cost'] ?? 0) > 0) {
                return $originalCosts[$candidate->id]['unit_cost'];
            }
            $quantity += (float) $candidate->quantity + (float) $candidate->bonus_qty;
            $value += $originalCosts[$candidate->id]['cost'] ?? 0.0;
        }

        return $quantity > 0 ? $value / $quantity : 0.0;
    }

    /**
     * Paid quantity and bonus per item still returnable from an invoice, excluding $exceptReturnId.
     * Bonus units are tracked apart so a return cannot refund free units as paid ones.
     *
     * @return array<int, array{quantity: float, bonus: float}> item_id => remaining paid quantity / bonus
     */
    public static function returnableDetail(PurchaseDocument $invoice, ?int $exceptReturnId = null): array
    {
        $received = [];
        foreach ($invoice->lines()->get() as $line) {
            $received[$line->item_id] ??= ['quantity' => 0.0, 'bonus' => 0.0];
            $received[$line->item_id]['quantity'] += (float) $line->quantity;
            $received[$line->item_id]['bonus'] += (float) $line->bonus_qty;
        }

        $returned = PurchaseDocumentLine::query()
            ->whereHas('purchaseDocument', fn ($q) => $q->where('original_document_id', $invoice->id)->where('is_posted', true)
                ->when($exceptReturnId, fn ($q, $id) => $q->whereKeyNot($id)))
            ->get()
            ->groupBy('item_id');

        foreach ($received as $itemId => $row) {
            $lines = $returned->get($itemId, collect());
            $received[$itemId] = [
                'quantity' => round($row['quantity'] - (float) $lines->sum(fn ($l) => (float) $l->quantity), 3),
                'bonus' => round($row['bonus'] - (float) $lines->sum(fn ($l) => (float) $l->bonus_qty), 3),
            ];
        }

        return $received;
    }

    /**
     * Paid quantity per item still returnable from an invoice, excluding $exceptReturnId.
     *
     * @return array<int, float> item_id => quantity
     */
    public static function returnableQuantities(PurchaseDocument $invoice, ?int $exceptReturnId = null): array
    {
        return array_map(fn (array $row): float => $row['quantity'], static::returnableDetail($invoice, $exceptReturnId));
    }

    /**
     * The still-returnable lines of an invoice as return line attributes (paid quantity and bonus
     * kept apart, line by line in invoice order).
     *
     * @return list<array<string, mixed>>
     */
    public static function returnLinesFor(PurchaseDocument $invoice): array
    {
        $available = static::returnableDetail($invoice);
        $rows = [];
        foreach ($invoice->lines()->orderBy('id')->get() as $line) {
            $left = $available[$line->item_id] ?? ['quantity' => 0.0, 'bonus' => 0.0];
            $qty = round(max(0, min((float) $line->quantity, $left['quantity'])), 3);
            $bonus = round(max(0, min((float) $line->bonus_qty, $left['bonus'])), 3);
            $available[$line->item_id] = ['quantity' => $left['quantity'] - $qty, 'bonus' => $left['bonus'] - $bonus];
            if ($qty <= 0 && $bonus <= 0) {
                continue;
            }

            $rows[] = $line->only(['item_id', 'unit_id', 'batch_no', 'unit_price', 'discount_percent', 'discount2_percent', 'tax_percent'])
                + [
                    'expiry_date' => $line->expiry_date?->toDateString(),
                    'quantity' => $qty,
                    'bonus_qty' => $bonus,
                    'bonus_percent' => 0,
                    'discount' => (float) $line->discount_percent > 0 || (float) $line->quantity <= 0 ? 0 : round((float) $line->discount * $qty / (float) $line->quantity, 2),
                    'discount2' => (float) $line->discount2_percent > 0 || (float) $line->quantity <= 0 ? 0 : round((float) $line->discount2 * $qty / (float) $line->quantity, 2),
                ];
        }

        return $rows;
    }

    /**
     * The invoice's additional discount carried over to a return, in proportion to the returned
     * taxable amount (the whole discount for a full return).
     *
     * @param  list<array<string, mixed>>  $returnLines
     */
    public static function returnAdditionalDiscount(PurchaseDocument $invoice, array $returnLines): float
    {
        $discount = (float) $invoice->additional_discount;
        $invoiceTaxable = (float) $invoice->net_total + $discount;
        if ($discount <= 0 || $invoiceTaxable <= 0) {
            return 0.0;
        }

        $returnTaxable = array_sum(array_map(fn (array $row): float => DocumentCalculator::line($row)['taxable'], $returnLines));

        return round(min($discount, $discount * $returnTaxable / $invoiceTaxable), 2);
    }

    /** A return against an invoice cannot return items that were not received, or more paid units / bonus than received. */
    public static function assertReturnable(PurchaseDocument $doc): void
    {
        if (! $doc->original_document_id) {
            return;
        }

        $invoice = PurchaseDocument::query()->findOrFail($doc->original_document_id);
        if (! $invoice->is_posted) {
            throw new ErpException(__('The original invoice is not posted.'));
        }

        $available = static::returnableDetail($invoice, $doc->id);
        $requested = [];
        foreach ($doc->lines as $line) {
            $requested[$line->item_id] ??= ['quantity' => 0.0, 'bonus' => 0.0];
            $requested[$line->item_id]['quantity'] += (float) $line->quantity;
            $requested[$line->item_id]['bonus'] += (float) $line->bonus_qty;
        }

        foreach ($requested as $itemId => $row) {
            $max = $available[$itemId] ?? null;
            $label = fn () => $doc->lines->firstWhere('item_id', $itemId)?->item?->label ?? $itemId;
            if ($max === null || $row['quantity'] > $max['quantity'] + 0.0001) {
                throw new ErpException(__('Cannot return more than received for :item: returnable :available, requested :requested.', [
                    'item' => $label(), 'available' => max(0, (float) ($max['quantity'] ?? 0)), 'requested' => $row['quantity'],
                ]));
            }
            if ($row['bonus'] > $max['bonus'] + 0.0001) {
                throw new ErpException(__('Cannot return more bonus than received for :item: returnable bonus :available, requested :requested.', [
                    'item' => $label(), 'available' => max(0, $max['bonus']), 'requested' => $row['bonus'],
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

    /**
     * Invoice: Dr inventory (stock lines' landed cost), Dr purchased services expense (service lines),
     * Dr input VAT, Cr supplier / L/C / cash, Cr expenses clearing (capitalised shipping and expenses).
     * Return: Dr supplier, Cr input VAT, Cr inventory at the original landed cost, Cr services expense;
     * the difference between the refund and the stock value goes to the purchase discount account.
     *
     * @param  array{stock: float, service: float}  $values  line values in local currency
     * @return array<int, array>
     */
    protected static function journalLines(PurchaseDocument $doc, PurchaseDocumentType $type, array $values = ['stock' => 0.0, 'service' => 0.0]): array
    {
        $sign = $type->isReturn() ? -1 : 1;
        $rate = (float) ($doc->exchange_rate ?: 1);
        $desc = $type->label().' #'.$doc->number.($doc->supplier_invoice_no ? ' / '.$doc->supplier_invoice_no : '');
        $extra = round(((float) $doc->shipping_cost + (float) $doc->purchase_expenses) * $rate, 2);
        $net = round((float) $doc->net_total * $rate, 2);
        $tax = round((float) $doc->tax_total * $rate, 2);
        $service = round($values['service'], 2);
        $lines = [];
        $lcAccount = $doc->accreditation_id ? $doc->accreditation?->lc_account_id : null;

        $inventory = $doc->accreditation?->inventory_account_id ?: Settings::accountOrFail('inventory');
        if ($type->isReturn()) {
            $stock = round($values['stock'], 2);
            $lines[] = SalesDocumentService::side($inventory, -($stock + $extra), $desc);
            $difference = round($net - $stock - $service, 2);
            if ($difference != 0.0) {
                $lines[] = SalesDocumentService::side(static::returnDifferenceAccount($inventory), -$difference, $desc);
            }
        } else {
            $lines[] = SalesDocumentService::side($inventory, round($net + $extra - $service, 2), $desc);
        }

        if ($service != 0.0) {
            $lines[] = SalesDocumentService::side(static::serviceExpenseAccount(), $sign * $service, $desc);
        }

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

        return array_values(array_filter($lines, fn (array $line): bool => (float) $line['debit'] > 0 || (float) $line['credit'] > 0));
    }

    /** Purchased service lines are expensed: the services expense account, else cost of goods sold. */
    public static function serviceExpenseAccount(): int
    {
        return Settings::account('service_expenses') ?? Settings::account('cogs') ?? Settings::accountOrFail('service_expenses');
    }

    /** Where a return's refund-vs-stock-value difference goes: purchase discount, else inventory adjustment, else inventory. */
    protected static function returnDifferenceAccount(int $inventory): int
    {
        return Settings::account('purchase_discount') ?? Settings::account('inventory_adjustment') ?? $inventory;
    }
}
