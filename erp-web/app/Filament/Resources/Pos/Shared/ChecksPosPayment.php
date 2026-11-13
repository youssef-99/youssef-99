<?php

namespace App\Filament\Resources\Pos\Shared;

use App\Filament\Resources\Pos\Support\PosLines;
use App\Services\Calc\DocumentCalculator;
use App\Services\PosService;
use Filament\Notifications\Notification;

/**
 * Create / edit pages of POS invoices: block "save and post" when the payment does not cover the total,
 * stamp the time, and open the 80mm receipt when "Print Invoice" is ticked.
 */
trait ChecksPosPayment
{
    protected function assertPaymentCovers(): void
    {
        if (empty($this->data['post_now'])) {
            return;
        }

        $totals = DocumentCalculator::totals(
            collect($this->data['lines'] ?? [])->map(fn (array $line) => PosLines::calculate($line))->all(),
            $this->data['additional_discount'] ?? 0,
            $this->data['additional_discount_percent'] ?? 0,
        );
        $payment = PosService::payment($totals['grand_total'], (float) ($this->data['cash_amount'] ?? 0), (float) ($this->data['card_amount'] ?? 0), (float) ($this->data['amount_received'] ?? 0));

        if ($payment['card_amount'] > $totals['grand_total']) {
            Notification::make()->danger()->title(__('The Visa / Mada amount cannot exceed the invoice total.'))->send();
            $this->halt();
        }

        if ($payment['short'] > 0) {
            Notification::make()->danger()
                ->title(__('The payment does not cover the invoice total. Remaining: :amount', ['amount' => number_format($payment['short'], 2)]))
                ->send();
            $this->halt();
        }
    }

    protected function receiptRedirect(): ?string
    {
        if (! empty($this->data['print_invoice']) && $this->record?->is_posted) {
            return PosDocumentTable::receiptUrl($this->record);
        }

        return null;
    }
}
