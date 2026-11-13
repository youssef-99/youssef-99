<?php

namespace App\Services\Zatca;

use App\Exceptions\ErpException;
use App\Models\SalesDocument;
use App\Services\Calc\DocumentCalculator;
use App\Services\JournalService;
use App\Services\SalesDocumentService;
use App\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ZATCA credit / debit notes (إشعارات دائنة / مدينة) against a posted sales invoice, without stock movement.
 *
 *  - Add Customer Discount (إضافة خصم للعميل): credit note for an amount (excl. VAT), allocated over the
 *    invoice lines in proportion to their taxable value (so each VAT rate gets its share).
 *  - Add Sales Invoice Correction (إضافة تصحيح فاتورة مبيعات): credit or debit note with explicit item lines.
 *
 * Posting: credit note — Dr sales discount (or sales returns / sales) net, Dr output VAT; Cr the invoice account
 * (customer, or cash for POS / cash sales). A debit note is the reverse. Every note carries the reason
 * (ZATCA BR-KSA-17) and the billing reference to the invoice.
 *
 * Stored in sales_documents with type `credit_note` / `debit_note`.
 */
class ZatcaNoteService
{
    public const CREDIT_NOTE = 'credit_note';

    public const DEBIT_NOTE = 'debit_note';

    public const TYPES = [self::CREDIT_NOTE, self::DEBIT_NOTE];

    /** Note types (نوع الإشعار). */
    public const NOTE_TYPES = [
        'customer_discount' => 'Customer Discount',
        'correction_credit' => 'Invoice Correction (Credit)',
        'correction_debit' => 'Invoice Correction (Debit)',
    ];

    /** Invoices a note can reference. */
    public const INVOICE_TYPES = ['sales_invoice', 'pos_invoice', 'cash_sales_invoice'];

    public static function createCustomerDiscount(SalesDocument $invoice, float $amount, string $reason, mixed $date = null, bool $post = true): SalesDocument
    {
        static::assertInvoice($invoice);
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new ErpException(__('Enter the discount amount.'));
        }

        $remaining = static::creditableAmount($invoice);
        if ($amount > $remaining + 0.001) {
            throw new ErpException(__('The discount exceeds the remaining invoice amount (:amount).', ['amount' => number_format($remaining, 2)]));
        }

        $invoice->loadMissing('lines');
        $taxable = $invoice->lines->sum(fn ($l) => (float) $l->gross - (float) $l->discount);
        $lines = [];
        $allocated = 0.0;
        $count = $invoice->lines->count();

        foreach ($invoice->lines->values() as $i => $line) {
            $share = $i === $count - 1
                ? round($amount - $allocated, 2)
                : round($taxable > 0 ? $amount * ((float) $line->gross - (float) $line->discount) / $taxable : $amount / max(1, $count), 2);
            $allocated += $share;
            if ($share <= 0) {
                continue;
            }
            $lines[] = ['item_id' => $line->item_id, 'unit_id' => $line->unit_id, 'quantity' => 1, 'unit_price' => $share, 'tax_percent' => (float) $line->tax_percent];
        }

        return static::create($invoice, self::CREDIT_NOTE, 'customer_discount', $reason, $lines, $date, $post);
    }

    /**
     * @param  'credit'|'debit'  $direction
     * @param  array<int, array{item_id: int, quantity: float|int|string, unit_price: float|int|string, tax_percent?: float|int|string|null}>  $lines
     */
    public static function createCorrection(SalesDocument $invoice, string $direction, string $reason, array $lines, mixed $date = null, bool $post = true): SalesDocument
    {
        static::assertInvoice($invoice);
        $invoice->loadMissing('lines');

        $prepared = [];
        foreach ($lines as $line) {
            if (blank($line['item_id'] ?? null) || (float) ($line['quantity'] ?? 0) <= 0) {
                continue;
            }
            $source = $invoice->lines->firstWhere('item_id', (int) $line['item_id']);
            $prepared[] = [
                'item_id' => (int) $line['item_id'],
                'unit_id' => $source?->unit_id,
                'quantity' => (float) $line['quantity'],
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'tax_percent' => (float) ($line['tax_percent'] ?? $source?->tax_percent ?? config('erp.default_vat_rate')),
            ];
        }

        if ($prepared === []) {
            throw new ErpException(__('The document has no items.'));
        }

        $type = $direction === 'debit' ? self::DEBIT_NOTE : self::CREDIT_NOTE;

        return static::create($invoice, $type, $direction === 'debit' ? 'correction_debit' : 'correction_credit', $reason, $prepared, $date, $post);
    }

    /** Invoice net after tax minus posted credit notes and returns against it. */
    public static function creditableAmount(SalesDocument $invoice): float
    {
        $credited = SalesDocument::query()
            ->where('original_document_id', $invoice->id)
            ->where('is_posted', true)
            ->where('type', '!=', self::DEBIT_NOTE)
            ->sum('net_total');

        return round(max(0, (float) $invoice->net_total - (float) $credited), 2);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected static function create(SalesDocument $invoice, string $type, string $noteType, string $reason, array $lines, mixed $date, bool $post): SalesDocument
    {
        if (blank(trim($reason))) {
            throw new ErpException(__('Enter the reason for the note.'));
        }

        return DB::transaction(function () use ($invoice, $type, $noteType, $reason, $lines, $date, $post) {
            $note = SalesDocument::query()->create([
                'type' => $type,
                'branch_id' => $invoice->branch_id,
                'warehouse_id' => $invoice->warehouse_id,
                'date' => Carbon::parse($date ?? now())->toDateString(),
                'time' => now()->format('H:i:s'),
                'customer_id' => $invoice->customer_id,
                'cash_customer_id' => $invoice->cash_customer_id,
                'points_customer_id' => $invoice->points_customer_id,
                'account_id' => $invoice->account_id,
                'salesman_id' => $invoice->salesman_id,
                'original_document_id' => $invoice->id,
                'payment_method' => $invoice->payment_method,
                'notes' => $reason,
                'zatca_note_type' => $noteType,
                'zatca_note_reason' => Str::limit($reason, 250, ''),
                'zatca_invoice_type' => $invoice->zatca_invoice_type,
            ]);

            foreach ($lines as $line) {
                $note->lines()->create($line);
            }

            static::recalculate($note);

            return $post ? static::post($note) : $note;
        });
    }

    public static function recalculate(SalesDocument $doc): SalesDocument
    {
        $doc->load('lines');
        $totals = DocumentCalculator::totals(
            $doc->lines->mapWithKeys(fn ($l) => [$l->id => $l->only(['quantity', 'unit_price', 'discount', 'discount_percent', 'tax_percent'])])->all(),
        );

        $doc->fill([
            'gross_total' => $totals['gross_total'],
            'items_discount' => $totals['items_discount'],
            'additional_discount' => 0,
            'net_total' => $totals['net_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
        ])->saveQuietly();

        return $doc;
    }

    public static function post(SalesDocument $doc): SalesDocument
    {
        if ($doc->is_posted) {
            throw new ErpException(__('This document is already posted.'));
        }

        DB::transaction(function () use ($doc) {
            static::recalculate($doc);
            $doc->load('originalDocument.customer');

            if ($doc->lines->isEmpty()) {
                throw new ErpException(__('The document has no items.'));
            }

            $sign = $doc->type === self::DEBIT_NOTE ? -1 : 1; // +1: credit note (debit sales, credit party)
            $desc = static::label($doc).' #'.$doc->number;
            $salesAccount = Settings::account('sales_discount') ?? Settings::account('sales_returns') ?? Settings::accountOrFail('sales');
            $original = $doc->originalDocument;
            $partyAccount = $doc->account_id ?: $original?->account_id ?: $original?->customer?->account_id;
            if (! $partyAccount || in_array($original?->type, ['pos_invoice'], true)) {
                $partyAccount = Settings::accountOrFail('cash');
            }

            $lines = [SalesDocumentService::side($salesAccount, $sign * (float) $doc->net_total, $desc, $doc->salesman_id)];
            if ((float) $doc->tax_total > 0) {
                $lines[] = SalesDocumentService::side(Settings::accountOrFail('vat_output'), $sign * (float) $doc->tax_total, $desc);
            }
            $lines[] = SalesDocumentService::side($partyAccount, -$sign * (float) $doc->grand_total, $desc, $doc->salesman_id);

            $entry = JournalService::record($doc, $doc->type, $doc->branch_id, $doc->date, $lines, $desc);

            $doc->forceFill([
                'is_posted' => true,
                'posted_at' => now(),
                'status' => 'confirmed',
                'journal_entry_id' => $entry->id,
            ])->saveQuietly();

            if (ZatcaService::enabled()) {
                app(ZatcaService::class)->prepare($doc);
            }

            $doc->refresh();
        });

        return ZatcaService::enabled() ? app(ZatcaService::class)->afterPost($doc) : $doc;
    }

    public static function unpost(SalesDocument $doc): SalesDocument
    {
        if (in_array($doc->zatca_status, ['reported', 'cleared', 'warning'], true)) {
            throw new ErpException(__('This invoice was reported to ZATCA. Issue a return or credit note instead.'));
        }

        return DB::transaction(function () use ($doc) {
            JournalService::removeFor($doc);
            $doc->forceFill(['is_posted' => false, 'posted_at' => null, 'journal_entry_id' => null, 'status' => 'draft'])->saveQuietly();

            return $doc;
        });
    }

    public static function label(SalesDocument $doc): string
    {
        return $doc->type === self::DEBIT_NOTE ? __('Debit Note') : __('Credit Note');
    }

    protected static function assertInvoice(SalesDocument $invoice): void
    {
        if (! $invoice->is_posted || ! in_array($invoice->type, self::INVOICE_TYPES, true)) {
            throw new ErpException(__('Select a posted sales invoice.'));
        }
    }
}
