<?php

namespace App\Services;

use App\Enums\StockDocumentType;
use App\Exceptions\ErpException;
use App\Models\StockDocument;
use App\Services\Calc\DocumentCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Adjustments, transfers and stocktakes.
 */
class StockDocumentService
{
    public static function numberKey(string $type): string
    {
        return "stock.{$type}";
    }

    public static function initialize(StockDocument $doc): void
    {
        $doc->fiscal_year ??= (int) Carbon::parse($doc->date ?? now())->format('Y');
        $doc->created_by ??= auth()->id();

        if (blank($doc->number)) {
            $doc->number = DocumentNumber::next(static::numberKey($doc->type), $doc->branch_id, $doc->fiscal_year);
        } else {
            DocumentNumber::bump(static::numberKey($doc->type), $doc->branch_id, $doc->fiscal_year, (string) $doc->number);
        }
    }

    public static function recalculate(StockDocument $doc): StockDocument
    {
        $doc->loadMissing('lines.item');
        $type = StockDocumentType::from($doc->type);
        $lines = [];
        foreach ($doc->lines as $line) {
            if (! $line->item_batch_id && ($line->batch_no || $line->expiry_date)) {
                $line->item_batch_id = StockService::resolveBatch($line->item, $line->batch_no, $line->expiry_date)?->id;
            }
            if ((float) $line->price <= 0) {
                $line->price = StockService::unitCost($line->item);
            }
            if ($type->isStocktake() && ! $doc->is_posted) {
                $line->system_qty = StockService::balance($line->item_id, $doc->warehouse_id, $line->item_batch_id, null, $doc->date->toDateString());
            }
            $lines[$line->id] = ['quantity' => $line->quantity, 'unit_price' => $line->price, 'discount' => $line->discount, 'discount_percent' => $line->discount_percent, 'tax_percent' => 0];
        }

        $totals = DocumentCalculator::totals($lines, $doc->additional_discount);
        foreach ($doc->lines as $line) {
            $c = $totals['lines'][$line->id];
            $line->fill(['gross' => $c['gross'], 'discount' => $c['discount'], 'net' => $c['net']]);
            if ($line->isDirty()) {
                $line->saveQuietly();
            }
        }

        $doc->fill([
            'gross_total' => $totals['gross_total'],
            'items_discount' => $totals['items_discount'],
            'additional_discount' => $totals['additional_discount'],
            'net_total' => $totals['net_total'],
        ])->saveQuietly();

        return $doc;
    }

    /** Whether the Post action applies (periodic stocktakes must be approved first). */
    public static function canPost(StockDocument $doc): bool
    {
        return ! ($doc->typeEnum() === StockDocumentType::PeriodicStocktake && ! $doc->is_approved);
    }

    public static function post(StockDocument $doc): StockDocument
    {
        if ($doc->is_posted) {
            throw new ErpException(__('This document is already posted.'));
        }

        $type = StockDocumentType::from($doc->type);

        if ($type === StockDocumentType::PeriodicStocktake && ! $doc->is_approved) {
            throw new ErpException(__('Periodic stocktake records must be approved before posting.'));
        }

        if ($type === StockDocumentType::OutboundTransfer) {
            if (! $doc->to_warehouse_id) {
                throw new ErpException(__('Select the destination warehouse.'));
            }
            if ((int) $doc->to_warehouse_id === (int) $doc->warehouse_id) {
                throw new ErpException(__('The destination warehouse must differ from the source warehouse.'));
            }
        }

        return DB::transaction(function () use ($doc, $type) {
            static::recalculate($doc);
            if ($doc->lines->isEmpty()) {
                throw new ErpException(__('The document has no items.'));
            }

            $value = 0.0;
            foreach ($doc->lines as $line) {
                $qty = (float) $line->quantity;
                $cost = (float) $line->price;

                switch ($type) {
                    case StockDocumentType::PositiveAdjustment:
                        StockService::move($doc, 'adjust_in', $line->item, $doc->branch_id, $doc->warehouse_id, $doc->date, $qty, $cost, $line->item_batch_id);
                        $value += $qty * $cost;
                        break;
                    case StockDocumentType::NegativeAdjustment:
                        StockService::move($doc, 'adjust_out', $line->item, $doc->branch_id, $doc->warehouse_id, $doc->date, -$qty, $cost, $line->item_batch_id, allowNegative: false);
                        $value -= $qty * $cost;
                        break;
                    case StockDocumentType::OutboundTransfer:
                        StockService::move($doc, 'transfer_out', $line->item, $doc->branch_id, $doc->warehouse_id, $doc->date, -$qty, $cost, $line->item_batch_id, allowNegative: false);
                        break;
                    case StockDocumentType::InboundTransfer:
                        StockService::move($doc, 'transfer_in', $line->item, $doc->branch_id, $doc->warehouse_id, $doc->date, $qty, $cost, $line->item_batch_id);
                        break;
                    case StockDocumentType::AnnualStocktake:
                    case StockDocumentType::PeriodicStocktake:
                        $diff = round($qty - (float) $line->system_qty, 3);
                        if ($diff != 0.0) {
                            StockService::move($doc, 'stocktake', $line->item, $doc->branch_id, $doc->warehouse_id, $doc->date, $diff, $cost, $line->item_batch_id);
                            $value += $diff * $cost;
                        }
                        break;
                }
            }

            $entryId = null;
            $value = round($value, 2);
            if ($value != 0.0 && ($inventory = Settings::account('inventory'))) {
                $offset = $doc->account_id ?: Settings::accountOrFail('inventory_adjustment');
                $desc = $type->label().' #'.$doc->number;
                $entryId = JournalService::record($doc, 'inventory', $doc->branch_id, $doc->date, [
                    SalesDocumentService::side($inventory, $value, $desc),
                    SalesDocumentService::side($offset, -$value, $desc),
                ], $desc)->id;
            }

            $doc->forceFill(['is_posted' => true, 'posted_at' => now(), 'journal_entry_id' => $entryId])->saveQuietly();

            // An outbound transfer creates the matching (draft) inbound transfer at the destination.
            if ($type === StockDocumentType::OutboundTransfer && $doc->to_warehouse_id) {
                static::createInboundFor($doc);
            }

            return $doc;
        });
    }

    /**
     * Approve a periodic stocktake record (اعتماد محضر الجرد الدوري) and, by default, post it.
     */
    public static function approve(StockDocument $doc, bool $post = true): StockDocument
    {
        if ($doc->type !== StockDocumentType::PeriodicStocktake->value) {
            throw new ErpException(__('Only periodic stocktake records need approval.'));
        }

        return DB::transaction(function () use ($doc, $post) {
            if (! $doc->is_approved) {
                $doc->forceFill(['is_approved' => true, 'approved_at' => now()])->saveQuietly();
            }

            return $post && ! $doc->is_posted ? static::post($doc) : $doc;
        });
    }

    public static function unpost(StockDocument $doc): StockDocument
    {
        if ($doc->type === StockDocumentType::OutboundTransfer->value
            && StockDocument::query()->where('related_document_id', $doc->id)->where('is_posted', true)->exists()) {
            throw new ErpException(__('The inbound transfer for this document was already received.'));
        }

        return DB::transaction(function () use ($doc) {
            StockService::removeFor($doc);
            JournalService::removeFor($doc);
            StockDocument::query()->where('related_document_id', $doc->id)->where('is_posted', false)->get()->each->delete();
            $doc->forceFill(['is_posted' => false, 'posted_at' => null, 'journal_entry_id' => null])->saveQuietly();

            return $doc;
        });
    }

    public static function createInboundFor(StockDocument $outbound): StockDocument
    {
        $inbound = new StockDocument([
            'type' => StockDocumentType::InboundTransfer->value,
            'branch_id' => $outbound->to_branch_id ?: $outbound->branch_id,
            'warehouse_id' => $outbound->to_warehouse_id,
            'to_branch_id' => $outbound->branch_id,     // "from" side for display
            'to_warehouse_id' => $outbound->warehouse_id,
            'date' => $outbound->date,
            'related_document_id' => $outbound->id,
            'salesman_id' => $outbound->salesman_id,
            'notes' => __('Outbound Transfer').' #'.$outbound->number,
        ]);
        static::initialize($inbound);
        $inbound->save();

        foreach ($outbound->lines as $line) {
            $inbound->lines()->create($line->only(['item_id', 'unit_id', 'batch_no', 'expiry_date', 'item_batch_id', 'quantity', 'price', 'gross', 'discount', 'discount_percent', 'net']));
        }

        static::recalculate($inbound);

        return $inbound;
    }
}
