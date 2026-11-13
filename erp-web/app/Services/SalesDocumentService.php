<?php

namespace App\Services;

use App\Enums\SalesDocumentType;
use App\Exceptions\ErpException;
use App\Models\Item;
use App\Models\PointsTransaction;
use App\Models\SalesDocument;
use App\Models\Warehouse;
use App\Services\Calc\DocumentCalculator;
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

        if (SalesDocumentType::from($doc->type)->isPos()) {
            $paid = (float) $doc->cash_amount + (float) $doc->card_amount;
            $doc->amount_received = $doc->amount_received > 0 ? $doc->amount_received : $paid;
            $doc->change_due = max(0, round((float) $doc->amount_received - (float) $doc->grand_total, 2));
            $doc->is_paid = $paid + 0.009 >= (float) $doc->grand_total || (float) $doc->amount_received + 0.009 >= (float) $doc->grand_total;
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

        if (! $type->isPostable()) {
            // Quotations are confirmed and customer orders are submitted for approval: no stock or journal.
            if ($doc->lines()->doesntExist()) {
                throw new ErpException(__('The document has no items.'));
            }
            $doc->forceFill(['is_posted' => true, 'posted_at' => now(), 'status' => 'confirmed'])->save();

            return $doc;
        }

        return DB::transaction(function () use ($doc, $type) {
            static::recalculate($doc);
            $doc->load('lines.item.category', 'customer', 'originalDocument.lines');

            if ($doc->lines->isEmpty()) {
                throw new ErpException(__('The document has no items.'));
            }

            if ($type->isReturn() && $doc->original_document_id) {
                static::assertReturnable($doc);
            }

            $warehouseId = $doc->warehouse_id ?? throw new ErpException(__('Select a warehouse.'));
            $direction = $type->stockDirection();
            $costTotal = 0.0;

            foreach ($doc->lines as $line) {
                /** @var Item $item */
                $item = $line->item;
                $qty = (float) $line->quantity + (float) $line->free_qty;

                if ($type->isReturn() && $doc->originalDocument) {
                    $orig = $doc->originalDocument->lines->firstWhere('item_id', $item->id);
                    $unitCost = (float) ($orig?->unit_cost ?: StockService::unitCost($item));
                } else {
                    $unitCost = StockService::unitCost($item);
                }

                $line->unit_cost = $unitCost;
                $line->saveQuietly();
                $costTotal += $qty * $unitCost;

                StockService::move(
                    $doc, $type->value, $item, $doc->branch_id, $warehouseId, $doc->date,
                    $direction * $qty, $unitCost, $line->item_batch_id,
                    allowNegative: (bool) config('erp.allow_negative_stock', true),
                );
            }

            $doc->cost_total = round($costTotal, 2);
            $entry = JournalService::record($doc, $type->isPos() ? 'pos' : ($type->isReturn() ? 'sales_return' : 'sales'), $doc->branch_id, $doc->date, static::journalLines($doc, $type), $type->label().' #'.$doc->number);

            $doc->forceFill([
                'is_posted' => true,
                'posted_at' => now(),
                'status' => 'confirmed',
                'journal_entry_id' => $entry->id,
            ])->saveQuietly();

            static::applyLoyalty($doc, $type);

            if ($type->isZatca() && config('erp.zatca.enabled', true)) {
                app(ZatcaService::class)->prepare($doc);
            }

            return $doc->refresh();
        });
    }

    public static function unpost(SalesDocument $doc): SalesDocument
    {
        if (in_array($doc->zatca_status, ['reported', 'cleared'], true)) {
            throw new ErpException(__('This invoice was reported to ZATCA. Issue a return or credit note instead.'));
        }

        if ($doc->convertedDocuments()->exists()) {
            throw new ErpException(__('This document was converted to a sales invoice. Delete the invoice first.'));
        }

        if ($doc->returns()->where('is_posted', true)->exists()) {
            throw new ErpException(__('This invoice has posted returns. Unpost the returns first.'));
        }

        return DB::transaction(function () use ($doc) {
            if ($doc->type === SalesDocumentType::CustomerOrder->value) {
                $doc->lines()->update(['approved_qty' => null]);
                $doc->forceFill(['rejection_reason' => null, 'approved_by' => null, 'approved_at' => null]);
            }
            StockService::removeFor($doc);
            JournalService::removeFor($doc);
            PointsTransaction::query()->where('sales_document_id', $doc->id)->get()->each(function (PointsTransaction $t) {
                $t->pointsCustomer?->decrement('points_balance', (float) $t->points);
                $t->delete();
            });
            $doc->forceFill(['is_posted' => false, 'posted_at' => null, 'journal_entry_id' => null, 'status' => 'draft', 'points_earned' => 0])->saveQuietly();

            return $doc;
        });
    }

    /**
     * Quantity still returnable per item of an original invoice: sold quantity minus the quantities
     * of its posted returns (optionally ignoring one return, e.g. the one being edited).
     *
     * @return array<int, array{sold: float, returned: float, available: float}> keyed by item id
     */
    public static function returnableQuantities(SalesDocument $original, ?int $exceptReturnId = null): array
    {
        $result = [];
        foreach ($original->lines()->get() as $line) {
            $result[$line->item_id] ??= ['sold' => 0.0, 'returned' => 0.0, 'available' => 0.0];
            $result[$line->item_id]['sold'] += (float) $line->quantity;
        }

        $returned = SalesDocument::query()
            ->where('original_document_id', $original->id)
            ->where('is_posted', true)
            ->when($exceptReturnId, fn ($q, $id) => $q->whereKeyNot($id))
            ->join('sales_document_lines', 'sales_document_lines.sales_document_id', '=', 'sales_documents.id')
            ->groupBy('sales_document_lines.item_id')
            ->selectRaw('sales_document_lines.item_id as item_id, SUM(sales_document_lines.quantity) as qty')
            ->pluck('qty', 'item_id');

        foreach ($result as $itemId => $row) {
            $result[$itemId]['returned'] = round((float) ($returned[$itemId] ?? 0), 3);
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

        // Debit side: customer account, or cash / card for POS & cash sales.
        if ($type->isPos() || $type->isCash() || in_array($doc->payment_method, ['cash', 'card', 'mixed'], true)) {
            $card = round((float) $doc->card_amount, 2);
            $card = min($card, (float) $doc->grand_total);
            $cash = round((float) $doc->grand_total - $card, 2);
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

    protected static function applyLoyalty(SalesDocument $doc, SalesDocumentType $type): void
    {
        if (! $doc->points_customer_id || ! $type->isPos()) {
            return;
        }

        $points = 0.0;
        foreach ($doc->lines as $line) {
            $points += (float) $line->net * (float) ($line->item?->category?->points_percent ?? 0) / 100;
        }
        $points = round($type->isReturn() ? -$points : $points, 2);

        if ($points != 0.0) {
            PointsTransaction::query()->create([
                'points_customer_id' => $doc->points_customer_id,
                'sales_document_id' => $doc->id,
                'date' => $doc->date,
                'points' => $points,
                'description' => $type->label().' #'.$doc->number,
            ]);
            $doc->pointsCustomer()->increment('points_balance', $points);
            $doc->forceFill(['points_earned' => $points])->saveQuietly();
        }
    }
}
