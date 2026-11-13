<?php

namespace App\Services;

use App\Enums\PurchaseDocumentType;
use App\Exceptions\ErpException;
use App\Models\PurchaseDocument;
use App\Models\Warehouse;
use App\Services\Calc\DocumentCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Totals and posting for purchase orders, invoices, returns, foreign and cash purchases.
 *
 * Invoice posting:  Dr Inventory (net + allocated expenses), Dr Input VAT, Cr Supplier (or L/C or cash).
 * Return posting:   the reverse, stock out.
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
                // the document-level discount reduces cost proportionally; expenses are allocated by value
                $lineCostLocal = $lineTaxable * $docDiscountFactor * $rate + $extra * $share;
                $qty = (float) $line->quantity + (float) $line->bonus_qty;
                $unitCost = $qty > 0 ? $lineCostLocal / $qty : 0;

                StockService::move($doc, $type->value, $item, $doc->branch_id, $warehouseId, $doc->date, $direction * $qty, $unitCost, $batch?->id, allowNegative: (bool) config('erp.allow_negative_stock', true));

                if (! $type->isReturn()) {
                    $item->forceFill(['purchase_price' => round((float) $line->unit_price * $rate, 4)] + ($line->sale_price ? ['sale_price' => $line->sale_price] : []))->saveQuietly();
                    $batch?->forceFill(['last_purchase_price' => round($unitCost, 4)] + ($line->sale_price ? ['sale_price' => $line->sale_price] : []))->saveQuietly();
                }
            }

            $entry = JournalService::record($doc, $type->isReturn() ? 'purchase_return' : 'purchase', $doc->branch_id, $doc->date, static::journalLines($doc, $type), $type->label().' #'.$doc->number);

            $doc->forceFill(['is_posted' => true, 'posted_at' => now(), 'status' => 'approved', 'journal_entry_id' => $entry->id])->saveQuietly();

            return $doc->refresh();
        });
    }

    public static function unpost(PurchaseDocument $doc): PurchaseDocument
    {
        return DB::transaction(function () use ($doc) {
            StockService::removeFor($doc);
            JournalService::removeFor($doc);
            $doc->forceFill(['is_posted' => false, 'posted_at' => null, 'journal_entry_id' => null, 'status' => 'draft'])->saveQuietly();

            return $doc;
        });
    }

    protected static function journalLines(PurchaseDocument $doc, PurchaseDocumentType $type): array
    {
        $sign = $type->isReturn() ? -1 : 1; // +1: debit inventory, credit supplier
        $rate = (float) ($doc->exchange_rate ?: 1);
        $desc = $type->label().' #'.$doc->number.($doc->supplier_invoice_no ? ' / '.$doc->supplier_invoice_no : '');
        $extra = round(((float) $doc->shipping_cost + (float) $doc->purchase_expenses) * $rate, 2);
        $net = round((float) $doc->net_total * $rate, 2);
        $tax = round((float) $doc->tax_total * $rate, 2);
        $lines = [];

        $inventory = $doc->accreditation?->inventory_account_id ?: Settings::accountOrFail('inventory');
        $lines[] = SalesDocumentService::side($inventory, $sign * ($net + $extra), $desc);

        if ($tax > 0) {
            $lines[] = SalesDocumentService::side(Settings::accountOrFail('vat_input'), $sign * $tax, $desc);
        }

        // Credit side
        if ($doc->accreditation_id && $doc->accreditation?->lc_account_id) {
            $payable = $doc->accreditation->lc_account_id;
        } else {
            $payable = $doc->account_id ?: $doc->supplier?->account_id ?: ($type->isCash() ? Settings::account('cash') : null);
        }

        if (! $payable) {
            throw new ErpException(__('The supplier has no account.'));
        }

        $lines[] = SalesDocumentService::side($payable, -$sign * ($net + $tax), $desc);

        if ($extra > 0) {
            $lines[] = SalesDocumentService::side(Settings::account('foreign_purchase_expenses') ?? $payable, -$sign * $extra, $desc);
        }

        return $lines;
    }
}
