<?php

namespace Tests\Feature\Sales;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\SalesDocument;
use App\Services\CustomerService;
use App\Services\SalesDocumentService;
use App\Services\Settings;
use App\Services\StockService;
use App\Services\Zatca\UblInvoiceBuilder;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\Concerns\CreatesInventory;

/**
 * Sales posting rules: payment method accounts, service items, returns of free units, return
 * prices / costs taken from the original invoice, and VAT rounded per rate like the ZATCA XML.
 */
class SalesPostingRulesTest extends ErpTestCase
{
    use CreatesInventory;

    protected function customer(): Customer
    {
        return CustomerService::afterSave(Customer::query()->create(['code' => '7101', 'name_ar' => 'عميل اختبار']));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $attributes
     */
    protected function document(string $type, array $lines, array $attributes = []): SalesDocument
    {
        $customer = Customer::query()->first() ?? $this->customer();
        $doc = SalesDocument::query()->create($attributes + [
            'type' => $type,
            'branch_id' => $this->branch()->id,
            'warehouse_id' => $this->mainWarehouse()->id,
            'date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'account_id' => $customer->account_id,
            'payment_method' => 'credit',
        ]);
        foreach ($lines as $line) {
            $doc->lines()->create($line + ['unit_price' => 20, 'tax_percent' => 15]);
        }

        return $doc->refresh();
    }

    /** @return array<int, float> account id => debit - credit */
    protected function byAccount(?JournalEntry $entry): array
    {
        $this->assertNotNull($entry);
        $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);

        return $entry->lines()->get()->groupBy('account_id')
            ->map(fn ($lines) => round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 2))
            ->all();
    }

    public function test_card_sales_invoice_debits_the_card_account(): void
    {
        $item = $this->makeItem('SP1');
        $this->receive($item, 10, 8);
        $invoice = SalesDocumentService::post($this->document('sales_invoice', [['item_id' => $item->id, 'quantity' => 2]], ['payment_method' => 'card']));

        $entry = $this->byAccount($invoice->journalEntry);
        $this->assertEquals(46, $entry[Settings::account('card')]);
        $this->assertArrayNotHasKey(Settings::account('cash'), $entry);
        $this->assertArrayNotHasKey($invoice->account_id, $entry);
    }

    public function test_cash_sales_invoice_paid_by_card_debits_the_card_account(): void
    {
        $item = $this->makeItem('SP2');
        $this->receive($item, 10, 8);
        $invoice = SalesDocumentService::post($this->document('cash_sales_invoice', [['item_id' => $item->id, 'quantity' => 1]], ['payment_method' => 'card', 'customer_id' => null, 'account_id' => null]));

        $entry = $this->byAccount($invoice->journalEntry);
        $this->assertEquals(23, $entry[Settings::account('card')]);
        $this->assertArrayNotHasKey(Settings::account('cash'), $entry);
    }

    public function test_service_items_book_no_cost_of_sales(): void
    {
        $service = $this->makeItem('SP3', ['item_type' => 'service', 'item_nature' => 'service']);
        $invoice = SalesDocumentService::post($this->document('sales_invoice', [['item_id' => $service->id, 'quantity' => 3]]));

        $this->assertEquals(0, (float) $invoice->cost_total);
        $this->assertEquals(0, (float) $invoice->lines()->sole()->unit_cost);
        $entry = $this->byAccount($invoice->journalEntry);
        $this->assertArrayNotHasKey(Settings::account('cogs'), $entry);
        $this->assertArrayNotHasKey(Settings::account('inventory'), $entry);
    }

    public function test_returns_bring_back_free_units_in_proportion(): void
    {
        $item = $this->makeItem('SP4');
        $this->receive($item, 20, 5);
        $invoice = SalesDocumentService::post($this->document('sales_invoice', [['item_id' => $item->id, 'quantity' => 4, 'free_qty' => 2]]));
        $this->assertEquals(14, StockService::balance($item->id));

        $first = SalesDocumentService::post($this->document('sales_return', [['item_id' => $item->id, 'quantity' => 1]], ['original_document_id' => $invoice->id]));
        $this->assertEquals(0.5, (float) $first->lines()->sole()->free_qty);
        $second = SalesDocumentService::post($this->document('sales_return', [['item_id' => $item->id, 'quantity' => 3]], ['original_document_id' => $invoice->id]));
        $this->assertEquals(1.5, (float) $second->lines()->sole()->free_qty);

        $this->assertEquals(20, StockService::balance($item->id), 'Every paid and free unit came back.');
        $this->assertEquals(22.5, (float) $second->cost_total, '(3 + 1.5) units at the original cost of 5.');
        $returnable = SalesDocumentService::returnableQuantities($invoice)[$item->id];
        $this->assertEquals([0.0, 2.0, 2.0], [$returnable['available'], $returnable['free_sold'], $returnable['free_returned']]);
    }

    public function test_return_takes_the_price_and_discount_of_the_original_line(): void
    {
        $item = $this->makeItem('SP5');
        $this->receive($item, 10, 5);
        $invoice = SalesDocumentService::post($this->document('sales_invoice', [['item_id' => $item->id, 'quantity' => 4, 'discount_percent' => 10]]));

        $return = SalesDocumentService::post($this->document('sales_return', [
            ['item_id' => $item->id, 'quantity' => 2, 'unit_price' => 99, 'discount_percent' => 0],
        ], ['original_document_id' => $invoice->id]));

        $line = $return->lines()->sole();
        $this->assertEquals(20, (float) $line->unit_price);
        $this->assertEquals(10, (float) $line->discount_percent);
        $this->assertEquals(round((float) $invoice->grand_total / 2, 2), (float) $return->grand_total);
    }

    public function test_return_cost_follows_the_matching_batch_else_the_weighted_cost(): void
    {
        $item = $this->makeItem('SP6', ['item_type' => 'batch_expiry']);
        $batchA = StockService::resolveBatch($item, 'A', now()->addYear()->toDateString());
        $batchB = StockService::resolveBatch($item, 'B', now()->addYears(2)->toDateString());
        $this->receive($item, 5, 5, line: ['item_batch_id' => $batchA->id]);
        $this->receive($item, 5, 9, line: ['item_batch_id' => $batchB->id]);
        $invoice = SalesDocumentService::post($this->document('sales_invoice', [
            ['item_id' => $item->id, 'item_batch_id' => $batchA->id, 'quantity' => 2],
            ['item_id' => $item->id, 'item_batch_id' => $batchB->id, 'quantity' => 2],
        ]));
        $invoice->lines()->where('item_batch_id', $batchA->id)->update(['unit_cost' => 5]);
        $invoice->lines()->where('item_batch_id', $batchB->id)->update(['unit_cost' => 9]);

        $byBatch = SalesDocumentService::post($this->document('sales_return', [['item_id' => $item->id, 'item_batch_id' => $batchB->id, 'quantity' => 1]], ['original_document_id' => $invoice->id]));
        $this->assertEquals(9, (float) $byBatch->lines()->sole()->unit_cost);

        $weighted = SalesDocumentService::post($this->document('sales_return', [['item_id' => $item->id, 'quantity' => 1]], ['original_document_id' => $invoice->id]));
        $this->assertEquals(7, (float) $weighted->lines()->sole()->unit_cost);
    }

    public function test_vat_is_rounded_per_rate_like_the_zatca_xml(): void
    {
        $standard = $this->makeItem('SP7');
        $zero = $this->makeItem('SP8', ['vat_rate' => 0]);
        $doc = $this->document('quotation', [
            ['item_id' => $standard->id, 'quantity' => 1, 'unit_price' => 1.03],
            ['item_id' => $standard->id, 'quantity' => 1, 'unit_price' => 1.03],
            ['item_id' => $standard->id, 'quantity' => 1, 'unit_price' => 1.03],
            ['item_id' => $zero->id, 'quantity' => 1, 'unit_price' => 7.77, 'tax_percent' => 0],
        ]);

        SalesDocumentService::recalculate($doc);
        $doc->refresh()->load('lines.item');
        $breakdown = UblInvoiceBuilder::breakdown($doc);

        $this->assertEquals(0.45, round((float) $doc->lines->sum('tax'), 2), 'Per-line tax is kept for display.');
        $this->assertEquals(0.46, (float) $doc->tax_total, '3.09 x 15% rounded once for the rate group.');
        $this->assertEquals($breakdown['tax_total'], (float) $doc->tax_total);
        $this->assertEquals($breakdown['tax_exclusive'], (float) $doc->net_total);
        $this->assertEquals(round($breakdown['tax_exclusive'] + $breakdown['tax_total'], 2), (float) $doc->grand_total);

        $doc->forceFill(['additional_discount' => 1.11])->saveQuietly();
        SalesDocumentService::recalculate($doc);
        $doc->refresh()->load('lines.item');
        $breakdown = UblInvoiceBuilder::breakdown($doc);
        $this->assertEquals($breakdown['tax_total'], (float) $doc->tax_total);
        $this->assertEquals($breakdown['tax_exclusive'], (float) $doc->net_total);
    }
}
