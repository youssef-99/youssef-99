<?php

namespace Tests\Feature\Sales;

use App\Exceptions\ErpException;
use App\Filament\Resources\Sales\QuotationResource\Pages\CreateQuotation;
use App\Filament\Resources\Sales\QuotationResource\Pages\ListQuotations;
use App\Models\Customer;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\SalesDocument;
use App\Models\StockMovement;
use App\Services\SalesDocumentService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\ErpTestCase;

class QuotationTest extends ErpTestCase
{
    public function test_quotation_is_confirmed_and_converted_to_a_draft_invoice_with_the_same_lines(): void
    {
        $this->seedDemo();
        $customer = Customer::query()->firstOrFail();
        $item = Item::query()->where('code', '1003')->firstOrFail();

        Livewire::test(CreateQuotation::class)
            ->fillForm(['customer_id' => $customer->id, 'account_id' => $customer->account_id, 'post_now' => true])
            ->set('data.lines', [
                'a' => ['item_id' => $item->id, 'quantity' => 4, 'unit_price' => 32, 'discount_percent' => 10, 'tax_percent' => 15],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $quotation = SalesDocument::query()->where('type', 'quotation')->latest('id')->firstOrFail();
        $this->assertTrue($quotation->is_posted);
        $this->assertSame('confirmed', $quotation->status);
        $this->assertNull($quotation->journal_entry_id);
        $this->assertFalse(StockMovement::query()->where('source_type', $quotation->getMorphClass())->where('source_id', $quotation->id)->exists());
        $this->assertEquals(132.48, (float) $quotation->grand_total);

        Livewire::test(ListQuotations::class)
            ->callAction(TestAction::make('convertToInvoice')->table($quotation))
            ->assertHasNoErrors();

        $invoice = SalesDocument::query()->where('type', 'sales_invoice')->where('source_document_id', $quotation->id)->firstOrFail();
        $this->assertFalse($invoice->is_posted);
        $this->assertSame($customer->account_id, $invoice->account_id);
        $this->assertCount(1, $invoice->lines);
        $this->assertEquals(4, (float) $invoice->lines->first()->quantity);
        $this->assertEquals(10, (float) $invoice->lines->first()->discount_percent);
        $this->assertEquals((float) $quotation->grand_total, (float) $invoice->grand_total);
        $this->assertNull(JournalEntry::query()->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->first());

        $this->expectException(ErpException::class);
        SalesDocumentService::convertToInvoice($quotation->refresh());
    }
}
