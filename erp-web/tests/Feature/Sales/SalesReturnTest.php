<?php

namespace Tests\Feature\Sales;

use App\Enums\SalesDocumentType;
use App\Exceptions\ErpException;
use App\Filament\Resources\Sales\SalesReturnResource\Pages\CreateSalesReturn;
use App\Models\SalesDocument;
use App\Models\StockMovement;
use App\Services\SalesDocumentService;
use App\Services\Settings;
use App\Services\StockService;
use Livewire\Livewire;
use Tests\ErpTestCase;

class SalesReturnTest extends ErpTestCase
{
    protected SalesDocument $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->invoice = SalesDocument::query()->where('type', 'sales_invoice')->where('is_posted', true)->orderBy('id')->firstOrFail();
    }

    /** Line of the demo invoice for item 1002 (10 sold, 2 already returned by the demo seeder). */
    protected function originalLine(): array
    {
        return $this->invoice->lines()->orderBy('id')->firstOrFail()->only(['item_id', 'unit_id', 'item_batch_id', 'unit_price', 'discount_percent', 'tax_percent']);
    }

    public function test_posting_a_return_reverses_the_invoice_entry_and_brings_stock_back(): void
    {
        $line = $this->originalLine();
        $balanceBefore = StockService::balance($line['item_id'], $this->invoice->warehouse_id);

        $component = Livewire::test(CreateSalesReturn::class)
            ->fillForm(['original_document_id' => $this->invoice->id]);

        $lines = $component->get('data.lines');
        $first = array_values($lines)[0];
        $this->assertEquals(8, (float) $first['quantity'], 'Defaults to sold minus earlier returns.');

        $key = array_key_first($lines);
        $component->set('data.lines', [$key => array_merge($first, ['quantity' => 3])])
            ->fillForm(['post_now' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $return = SalesDocument::query()->where('type', 'sales_return')->latest('id')->firstOrFail();
        $this->assertTrue($return->is_posted);
        $this->assertSame($this->invoice->id, $return->original_document_id);
        $this->assertEquals(3, (float) $return->lines->sum('quantity'));

        $entry = $return->journalEntry()->with('lines')->firstOrFail();
        $this->assertEquals($entry->total_debit, $entry->total_credit);
        $this->assertEquals((float) $return->grand_total, (float) $entry->lines->firstWhere('account_id', $this->invoice->account_id)->credit);
        $returnsAccount = Settings::account('sales_returns') ?? Settings::account('sales');
        $this->assertEquals((float) $return->net_total, (float) $entry->lines->firstWhere('account_id', $returnsAccount)->debit);
        $this->assertEquals((float) $return->tax_total, (float) $entry->lines->firstWhere('account_id', Settings::account('vat_output'))->debit);

        $movement = StockMovement::query()->where('source_type', $return->getMorphClass())->where('source_id', $return->id)->firstOrFail();
        $this->assertEquals(3, (float) $movement->qty_in);
        $this->assertEquals($balanceBefore + 3, StockService::balance($line['item_id'], $this->invoice->warehouse_id));
    }

    public function test_returning_more_than_sold_minus_earlier_returns_is_rejected(): void
    {
        $return = SalesDocument::query()->create([
            'type' => 'sales_return', 'branch_id' => $this->invoice->branch_id, 'warehouse_id' => $this->invoice->warehouse_id,
            'date' => now(), 'customer_id' => $this->invoice->customer_id, 'account_id' => $this->invoice->account_id,
            'original_document_id' => $this->invoice->id,
        ]);
        $return->lines()->create($this->originalLine() + ['quantity' => 9]);

        try {
            SalesDocumentService::post($return);
            $this->fail('An over-return must be rejected.');
        } catch (ErpException $e) {
            $this->assertStringContainsString('8', $e->getMessage());
        }

        $this->assertFalse($return->refresh()->is_posted);
        $this->assertFalse(StockMovement::query()->where('source_type', $return->getMorphClass())->where('source_id', $return->id)->exists());

        $return->lines()->update(['quantity' => 8]);
        SalesDocumentService::post($return->refresh());
        $this->assertTrue($return->refresh()->is_posted);
    }

    public function test_credit_notes_on_the_invoice_do_not_reduce_the_returnable_quantity(): void
    {
        $note = SalesDocument::query()->create([
            'type' => SalesDocumentType::CreditNote->value, 'branch_id' => $this->invoice->branch_id, 'date' => now(),
            'customer_id' => $this->invoice->customer_id, 'original_document_id' => $this->invoice->id,
        ]);
        $note->lines()->create($this->originalLine() + ['quantity' => 8]);
        $note->forceFill(['is_posted' => true])->saveQuietly();

        $itemId = $this->originalLine()['item_id'];
        $this->assertEquals(8, SalesDocumentService::returnableQuantities($this->invoice)[$itemId]['available']);

        $this->assertTrue(SalesDocumentType::CreditNote->isReturn());
        $this->assertFalse(SalesDocumentType::DebitNote->isReturn());
        $this->assertSame(0, SalesDocumentType::CreditNote->stockDirection());
        $this->assertSame(0, SalesDocumentType::DebitNote->stockDirection());
        $this->assertTrue(SalesDocumentType::DebitNote->isPostable());
        $this->assertTrue(SalesDocumentType::CreditNote->isZatca());
    }

    public function test_an_invoice_with_posted_returns_cannot_be_unposted(): void
    {
        $this->expectException(ErpException::class);
        SalesDocumentService::unpost($this->invoice);
    }
}
