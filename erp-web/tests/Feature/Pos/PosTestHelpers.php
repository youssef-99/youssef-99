<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use App\Services\Settings;
use Illuminate\Support\Facades\Http;

/**
 * Helpers for POS tests: draft POS invoices from demo items and journal lookups.
 */
trait PosTestHelpers
{
    protected function setUpPos(): void
    {
        $this->seedDemo();
        Http::preventStrayRequests();
    }

    protected function item(string $code): Item
    {
        return Item::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @param  array<string, float|int>  $quantities  item code => quantity
     * @param  array<string, mixed>  $attributes
     */
    protected function draftPos(array $quantities, array $attributes = [], string $type = 'pos_invoice'): SalesDocument
    {
        $doc = SalesDocument::query()->create(array_merge([
            'type' => $type,
            'branch_id' => $this->branchId(),
            'date' => now()->toDateString(),
            'time' => '12:00:00',
        ], $attributes));

        foreach ($quantities as $code => $qty) {
            $item = $this->item((string) $code);
            $doc->lines()->create([
                'item_id' => $item->id,
                'unit_id' => $item->unit_id,
                'quantity' => $qty,
                'unit_price' => (float) $item->sale_price,
                'tax_percent' => (float) $item->vat_rate,
                'item_batch_id' => $item->batches()->value('id'),
            ]);
        }

        SalesDocumentService::recalculate($doc);

        return $doc->refresh();
    }

    protected function branchId(): int
    {
        return (int) Branch::query()->value('id');
    }

    /** @return array{debit: float, credit: float} */
    protected function accountTotals(JournalEntry $entry, string $key): array
    {
        $lines = $entry->lines()->where('account_id', Settings::account($key))->get();

        return ['debit' => round((float) $lines->sum('debit'), 2), 'credit' => round((float) $lines->sum('credit'), 2)];
    }
}
