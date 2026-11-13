<?php

namespace App\Services;

use App\Enums\SalesDocumentType;
use App\Exceptions\ErpException;
use App\Models\Item;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\Warehouse;
use App\Services\Calc\DocumentCalculator;
use App\Services\Zatca\ZatcaNoteService;
use App\Services\Zatca\ZatcaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Totals, posting (stock + journal + ZATCA + loyalty) for every sales document type.
 */
class SalesDocumentService
{
    public static function numberKey(SalesDocumentType|string $type): string
    {
        return 'sales.'.($type instanceof SalesDocumentType ? $type->value : $type);
    }

    /** Assign uuid / number / fiscal year to a new document. */
    public static function initialize(SalesDocument $doc): void
    {
        $doc->uuid ??= (string) Str::uuid();
        $doc->fiscal_year ??= (int) Carbon::parse($doc->date ?? now())->format('Y');
        $doc->warehouse_id ??= Warehouse::query()->where('branch_id', $doc->branch_id)->value('id');
        $doc->created_by ??= auth()->id();

        if (blank($doc->number)) {
            $doc->number = DocumentNumber::next(static::numberKey($doc->type), $doc->branch_id, $doc->fiscal_year);
        } else {
            DocumentNumber::bump(static::numberKey($doc->type), $doc->branch_id, $doc->fiscal_year, (string) $doc->number);
        }
    }

    /** Recalculate line amounts and document totals from the stored lines. */
    public static function recalculate(SalesDocument $doc): SalesDocument
    {
        $doc->loadMissing('lines');
        $lines = $doc->lines->mapWithKeys(fn ($l) => [$l->id => $l->only(['quantity', 'unit_price', 'discount', 'discount_percent', 'tax_percent'])])->all();
        $totals = DocumentCalculator::totals($lines, $doc->additional_discount, $doc->additional_discount_percent);

        foreach ($doc->lines as $line) {
            $c = $totals['lines'][$line->id];
            $line->fill(['gross' => $c['gross'], 'discount' => $c['discount'], 'tax' => $c['tax'], 'net' => $c['net']]);
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
        ]);

        if (SalesDocumentType::tryFrom((string) $doc->type)?->isPos()) {
            $payment = PosService::payment((float) $doc->grand_total, (float) $doc->cash_amount, (float) $doc->card_amount, (float) $doc->amount_received);
            $doc->change_due = $payment['change_due'];
            $doc->is_paid = $payment['is_paid'];
        }

        $doc->saveQuietly();

        return $doc;
    }

    public static function post(SalesDocument $doc): SalesDocument
    {
        $type = SalesDocumentType::from($doc->type);

        if ($doc->is_posted) {
            throw new ErpException(__('This document is already posted.'));
        }

        if ($type->isNote()) {
            return DB::transaction(function () use ($doc) {
                PostingLock::lock($doc, expectPosted: false);

                return ZatcaNoteService::post($doc);
            });
        }

        if (! $type->isPostable()) {
            // Quotations are confirmed and customer orders are submitted for approval: no stock or journal.
            return DB::transaction(function () use ($doc) {
                PostingLock::lock($doc, expectPosted: false);
                if ($doc->lines()->doesntExist()) {
                    throw new ErpException(__('The document has no items.'));
                }
                $doc->forceFill(['is_posted' => true, 'posted_at' => now(), 'status' => 'confirmed'])->save();

                return $doc;
            });
        }

        $doc = DB::transaction(function () use ($doc, $type) {
            PostingLock::lock($doc, expectPosted: false);

            $isReturnAgainstInvoice = $type->isReturn() && $doc->original_document_id;
            if ($isReturnAgainstInvoice) {
                static::applyOriginalTerms($doc);
            }

            static::recalculate($doc);
            $doc->load('lines.item.category', 'customer', 'originalDocument.lines');

            if ($doc->lines->isEmpty()) {
                throw new ErpException(__('The document has no items.'));
            }

            if ($isReturnAgainstInvoice) {
                static::assertReturnable($doc);
            }

            $warehouseId = $doc->warehouse_id ?? throw new ErpException(__('Select a warehouse.'));
            $direction = $type->stockDirection();
            $costTotal = 0.0;

            foreach ($doc->lines as $line) {
                /** @var Item $item */
                $item = $line->item;
                $qty = (float) $line->quantity + (float) $line->free_qty;

                if ($item->isService()) {
                    $unitCost = 0.0;
                } elseif ($type->isReturn() && $doc->originalDocument) {
                    $unitCost = static::originalUnitCost($doc->originalDocument->lines, (int) $line->item_id, $line->item_batch_id) ?: StockService::unitCost($item);
                } else {
                    $unitCost = StockService::unitCost($item);
                }

                $line->unit_cost = $unitCost;
                $line->saveQuietly();
                $costTotal += $qty * $unitCost;

                if ($direction !== 0) {
                    StockService::move(
                        $doc, $type->value, $item, $doc->branch_id, $warehouseId, $doc->date,
                        $direction * $qty, $unitCost, $line->item_batch_id,
                        allowNegative: (bool) config('erp.allow_negative_stock', true),
                    );
                }
            }

            $doc->cost_total = round($costTotal, 2);
            $entry = JournalService::record($doc, $type->isPos() ? 'pos' : ($type->isReturn() ? 'sales_return' : 'sales'), $doc->branch_id, $doc->date, static::journalLines($doc, $type), $type->label().' #'.$doc->number);

            $doc->forceFill([
                'is_posted' => true,
                'posted_at' => now(),
                'status' => 'confirmed',
                'journal_entry_id' => $entry->id,
            ])->saveQuietly();

            if ($type->isPos()) {
                LoyaltyPointsService::earn($doc);
            }

            if ($type->isZatca() && ZatcaService::enabled()) {
                app(ZatcaService::class)->prepare($doc);
            }

            return $doc->refresh();
        });

        return ZatcaService::enabled() ? app(ZatcaService::class)->afterPost($doc) : $doc;
    }

    public static function unpost(SalesDocument $doc): SalesDocument
    {
        if (SalesDocumentType::tryFrom((string) $doc->type)?->isNote()) {
            return DB::transaction(function () use ($doc) {
                PostingLock::lock($doc, expectPosted: true);

                return ZatcaNoteService::unpost($doc);
            });
        }

        ZatcaService::assertCanUnpost($doc);

        if ($doc->convertedDocuments()->exists()) {
            throw new ErpException(__('This document was converted to a sales invoice. Delete the invoice first.'));
        }

        if ($doc->returns()->where('is_posted', true)->exists()) {
            throw new ErpException(__('This invoice has posted returns. Unpost the returns first.'));
        }

        return DB::transaction(function () use ($doc) {
            PostingLock::lock($doc, expectPosted: true);

            if ($doc->type === SalesDocumentType::CustomerOrder->value) {
                $doc->lines()->update(['approved_qty' => null]);
                $doc->forceFill(['rejection_reason' => null, 'approved_by' => null, 'approved_at' => null]);
            }
            StockService::removeFor($doc);
            JournalService::removeFor($doc);
            LoyaltyPointsService::reverse($doc);
            $doc->forceFill(['is_posted' => false, 'posted_at' => null, 'journal_entry_id' => null, 'status' => 'draft', 'points_earned' => 0])->saveQuietly();

            return $doc;
        });
    }

    /**
     * A return refunds what was charged: each line takes the unit price, discount and VAT of the
     * matching original invoice line (same item and batch, else same item), and the promotional
     * free units of the original are returned in proportion to the paid quantity returned.
     */
    public static function applyOriginalTerms(SalesDocument $return): void
    {
        $original = SalesDocument::query()->with('lines')->find($return->original_document_id);
        if (! $original) {
            return;
        }

        $returnable = static::returnableQuantities($original, $return->id);
        /** @var array<int, float> $returnedNow paid quantity of this return so far, per item */
        $returnedNow = [];
        /** @var array<int, float> $freeNow free quantity of this return so far, per item */
        $freeNow = [];

        foreach ($return->lines()->orderBy('id')->get() as $line) {
            $source = static::matchOriginalLine($original->lines, (int) $line->item_id, $line->item_batch_id);
            if (! $source) {
                continue;
            }

            $line->fill($source->only(['unit_price', 'discount_percent', 'tax_percent']));
            if ((float) $source->discount_percent <= 0) {
                $line->discount = (float) $source->quantity > 0
                    ? round((float) $source->discount * (float) $line->quantity / (float) $source->quantity, 2)
                    : 0;
            }

            $itemId = (int) $line->item_id;
            $row = $returnable[$itemId] ?? null;
            $free = 0.0;
            if ($row && $row['sold'] > 0 && $row['free_sold'] > 0) {
                $returnedNow[$itemId] = ($returnedNow[$itemId] ?? 0.0) + (float) $line->quantity;
                $paidReturned = min($row['sold'], $row['returned'] + $returnedNow[$itemId]);
                $freeDue = round($row['free_sold'] * $paidReturned / $row['sold'], 3);
                $free = max(0.0, round($freeDue - $row['free_returned'] - ($freeNow[$itemId] ?? 0.0), 3));
                $freeNow[$itemId] = ($freeNow[$itemId] ?? 0.0) + $free;
            }
            $line->free_qty = $free;

            if ($line->isDirty()) {
                $line->save();
            }
        }

        $return->unsetRelation('lines');
    }

    /**
     * The original invoice line a return line refunds: same item and batch, else the first line of the item.
     *
     * @param  iterable<SalesDocumentLine>  $lines
     */
    public static function matchOriginalLine(iterable $lines, int $itemId, int|string|null $batchId): ?SalesDocumentLine
    {
        $fallback = null;
        foreach ($lines as $candidate) {
            if ((int) $candidate->item_id !== $itemId) {
                continue;
            }
            if ((int) $candidate->item_batch_id === (int) $batchId) {
                return $candidate;
            }
            $fallback ??= $candidate;
        }

        return $fallback;
    }

    /**
     * Unit cost a returned line comes back at: the cost of the original line with the same item and
     * batch, else the quantity-weighted cost of every original line of the item.
     *
     * @param  iterable<SalesDocumentLine>  $originalLines
     */
    public static function originalUnitCost(iterable $originalLines, int $itemId, int|string|null $batchId): float
    {
        $quantity = $value = 0.0;
        foreach ($originalLines as $candidate) {
            if ((int) $candidate->item_id !== $itemId) {
                continue;
            }
            if ($batchId && (int) $candidate->item_batch_id === (int) $batchId && (float) $candidate->unit_cost > 0) {
                return (float) $candidate->unit_cost;
            }
            $qty = (float) $candidate->quantity + (float) $candidate->free_qty;
            $quantity += $qty;
            $value += $qty * (float) $candidate->unit_cost;
        }

        return $quantity > 0 ? round($value / $quantity, 4) : 0.0;
    }

    /**
     * Quantity still returnable per item of an original invoice: sold quantity minus the quantities
     * of its posted returns (optionally ignoring one return, e.g. the one being edited).
     *
     * Promotional free units are tracked alongside (free_sold / free_returned): they follow the paid
     * quantity back in proportion (see applyOriginalTerms()).
     *
     * @return array<int, array{sold: float, returned: float, available: float, free_sold: float, free_returned: float}> keyed by item id
     */
    public static function returnableQuantities(SalesDocument $original, ?int $exceptReturnId = null): array
    {
        $result = [];
        foreach ($original->lines()->get() as $line) {
            $result[$line->item_id] ??= ['sold' => 0.0, 'returned' => 0.0, 'available' => 0.0, 'free_sold' => 0.0, 'free_returned' => 0.0];
            $result[$line->item_id]['sold'] += (float) $line->quantity;
            $result[$line->item_id]['free_sold'] += (float) $line->free_qty;
        }

        $returnType = SalesDocumentType::tryFrom((string) $original->type)?->returnType();

        $returned = SalesDocument::query()
            ->where('original_document_id', $original->id)
            ->where('sales_documents.type', $returnType?->value ?? '')
            ->where('is_posted', true)
            ->when($exceptReturnId, fn ($q, $id) => $q->whereKeyNot($id))
            ->join('sales_document_lines', 'sales_document_lines.sales_document_id', '=', 'sales_documents.id')
            ->groupBy('sales_document_lines.item_id')
            ->selectRaw('sales_document_lines.item_id as item_id, SUM(sales_document_lines.quantity) as qty, SUM(sales_document_lines.free_qty) as free_qty')
            ->get()
            ->keyBy('item_id');

        foreach ($result as $itemId => $row) {
            $result[$itemId]['returned'] = round((float) ($returned[$itemId]->qty ?? 0), 3);
            $result[$itemId]['free_returned'] = round((float) ($returned[$itemId]->free_qty ?? 0), 3);
            $result[$itemId]['available'] = round(max(0, $row['sold'] - $result[$itemId]['returned']), 3);
        }

        return $result;
    }

    /**
     * A return may only contain items of its original invoice, and the returned quantity of each item
     * cannot exceed the sold quantity minus earlier returns.
     */
    public static function assertReturnable(SalesDocument $return): void
    {
        $original = $return->originalDocument ?? throw new ErpException(__('Select the original invoice.'));

        if (! $original->is_posted) {
            throw new ErpException(__('The original invoice is not posted.'));
        }

        $returnable = static::returnableQuantities($original, $return->id);
        $requested = [];
        foreach ($return->lines()->with('item')->get() as $line) {
            $requested[$line->item_id] ??= ['qty' => 0.0, 'item' => $line->item];
            $requested[$line->item_id]['qty'] += (float) $line->quantity;
        }

        foreach ($requested as $itemId => $row) {
            $label = $row['item']?->label ?? (string) $itemId;
            if (! isset($returnable[$itemId])) {
                throw new ErpException(__('Item :item is not on the original invoice.', ['item' => $label]));
            }
            if ($row['qty'] > $returnable[$itemId]['available'] + 0.0005) {
                throw new ErpException(__('Returned quantity of :item (:qty) exceeds the sold quantity minus earlier returns (:available).', [
                    'item' => $label,
                    'qty' => (float) $row['qty'],
                    'available' => $returnable[$itemId]['available'],
                ]));
            }
        }
    }

    /**
     * Copy a quotation or an approved customer order into a new draft sales invoice
     * (customer orders use the approved quantities).
     */
    public static function convertToInvoice(SalesDocument $source): SalesDocument
    {
        $type = SalesDocumentType::from($source->type);

        if (! in_array($type, [SalesDocumentType::Quotation, SalesDocumentType::CustomerOrder], true)) {
            throw new ErpException(__('Only quotations and customer orders can be converted to an invoice.'));
        }

        if ($type === SalesDocumentType::CustomerOrder && ! in_array($source->status, ['approved', 'partially_approved'], true)) {
            throw new ErpException(__('Approve the customer order before converting it to an invoice.'));
        }

        if ($source->status === 'rejected') {
            throw new ErpException(__('A rejected document cannot be converted.'));
        }

        if ($source->convertedDocuments()->exists()) {
            throw new ErpException(__('This document was already converted to a sales invoice.'));
        }

        return DB::transaction(function () use ($source, $type) {
            $source->loadMissing('lines', 'customer');
            $date = now()->startOfDay();
            $paymentTerm = (int) ($source->customer?->payment_term_days ?? 0);

            $invoice = SalesDocument::query()->create([
                'type' => SalesDocumentType::SalesInvoice->value,
                'branch_id' => $source->branch_id,
                'warehouse_id' => $source->warehouse_id,
                'date' => $date,
                'due_date' => $paymentTerm > 0 ? $date->copy()->addDays($paymentTerm) : $source->due_date,
                'customer_id' => $source->customer_id,
                'account_id' => $source->account_id ?: $source->customer?->account_id,
                'salesman_id' => $source->salesman_id,
                'sales_policy_id' => $source->sales_policy_id,
                'payment_method' => $source->payment_method ?: 'credit',
                'manual_no' => $source->manual_no,
                'notes' => $source->notes,
                'additional_discount_percent' => $source->additional_discount_percent,
                'additional_discount' => (float) $source->additional_discount_percent > 0 ? 0 : $source->additional_discount,
                'source_document_id' => $source->id,
            ]);

            $copied = 0;
            foreach ($source->lines as $line) {
                $quantity = $type === SalesDocumentType::CustomerOrder ? (float) $line->approved_qty : (float) $line->quantity;
                if ($quantity <= 0) {
                    continue;
                }
                $invoice->lines()->create([
                    'item_id' => $line->item_id,
                    'unit_id' => $line->unit_id,
                    'item_batch_id' => $line->item_batch_id,
                    'quantity' => $quantity,
                    'free_qty' => $line->free_qty,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'tax_percent' => $line->tax_percent,
                ]);
                $copied++;
            }

            if ($copied === 0) {
                throw new ErpException(__('The document has no items.'));
            }

            return static::recalculate($invoice->refresh());
        });
    }

    /** @return array<int, array> */
    protected static function journalLines(SalesDocument $doc, SalesDocumentType $type): array
    {
        $sign = $type->isReturn() ? -1 : 1; // +1: debit customer/cash, credit sales
        $lines = [];
        $desc = $type->label().' #'.$doc->number;

        // Debit side: customer account, or cash / card. POS (and mixed payments) split by the card
        // amount; other documents go wholly to the card account (card), the cash account (cash and
        // cash sales) or the customer (credit).
        $grandTotal = round((float) $doc->grand_total, 2);
        $method = (string) $doc->payment_method;
        $card = $cash = null;
        if ($type->isPos() || $method === 'mixed') {
            $card = min(round((float) $doc->card_amount, 2), $grandTotal);
            $cash = round($grandTotal - $card, 2);
        } elseif ($method === 'card') {
            $card = $grandTotal;
            $cash = 0.0;
        } elseif ($type->isCash() || $method === 'cash') {
            $card = 0.0;
            $cash = $grandTotal;
        }

        if ($card !== null) {
            if ($card > 0) {
                $lines[] = static::side(Settings::accountOrFail('card'), $sign * $card, $desc);
            }
            if ($cash > 0) {
                $cashAccount = $type->isCash() && $doc->account_id ? $doc->account_id : Settings::accountOrFail('cash');
                $lines[] = static::side($cashAccount, $sign * $cash, $desc);
            }
        } else {
            $account = $doc->account_id ?: $doc->customer?->account_id;
            if (! $account) {
                throw new ErpException(__('The customer has no account.'));
            }
            $lines[] = static::side($account, $sign * (float) $doc->grand_total, $desc, $doc->salesman_id);
        }

        // Credit side: sales (gross), discount, VAT.
        $discount = round((float) $doc->items_discount + (float) $doc->additional_discount, 2);
        $discountAccount = Settings::account('sales_discount');
        $salesAccount = $type->isReturn() ? (Settings::account('sales_returns') ?? Settings::accountOrFail('sales')) : Settings::accountOrFail('sales');

        if ($discountAccount && $discount > 0) {
            $lines[] = static::side($salesAccount, -$sign * (float) $doc->gross_total, $desc, $doc->salesman_id);
            $lines[] = static::side($discountAccount, $sign * $discount, $desc);
        } else {
            $lines[] = static::side($salesAccount, -$sign * (float) $doc->net_total, $desc, $doc->salesman_id);
        }

        if ((float) $doc->tax_total > 0) {
            $lines[] = static::side(Settings::accountOrFail('vat_output'), -$sign * (float) $doc->tax_total, $desc);
        }

        // Perpetual inventory: COGS vs inventory.
        if ((float) $doc->cost_total > 0 && Settings::account('cogs') && Settings::account('inventory')) {
            $lines[] = static::side(Settings::account('cogs'), $sign * (float) $doc->cost_total, $desc);
            $lines[] = static::side(Settings::account('inventory'), -$sign * (float) $doc->cost_total, $desc);
        }

        return $lines;
    }

    /** Positive amount = debit, negative = credit. */
    public static function side(int $accountId, float $amount, ?string $description = null, ?int $salesmanId = null): array
    {
        return [
            'account_id' => $accountId,
            'debit' => $amount > 0 ? round($amount, 2) : 0,
            'credit' => $amount < 0 ? round(-$amount, 2) : 0,
            'description' => $description,
            'salesman_id' => $salesmanId,
        ];
    }
}
