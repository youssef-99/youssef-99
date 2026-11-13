<?php

namespace Tests\Feature;

use App\Filament\Resources\Sales\SalesInvoiceResource\Pages\CreateSalesInvoice;
use App\Models\Customer;
use App\Models\Item;
use App\Models\SalesDocument;
use Livewire\Livewire;
use Tests\ErpTestCase;

class SalesInvoiceFormTest extends ErpTestCase
{
    public function test_create_and_post_sales_invoice_through_the_form(): void
    {
        $this->seedDemo();
        $customer = Customer::query()->first();
        $item = Item::query()->where('code', '1002')->first();

        Livewire::test(CreateSalesInvoice::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'post_now' => true,
            ])
            ->set('data.lines', [
                'a' => ['item_id' => $item->id, 'quantity' => 3, 'unit_price' => 20, 'discount_percent' => 10, 'tax_percent' => 15, 'item_batch_id' => $item->batches()->value('id')],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $doc = SalesDocument::query()->where('type', 'sales_invoice')->latest('id')->first();
        $this->assertTrue($doc->is_posted);
        $this->assertEquals(60.00, (float) $doc->gross_total);
        $this->assertEquals(54.00, (float) $doc->net_total);
        $this->assertEquals(8.10, (float) $doc->tax_total);
        $this->assertEquals(62.10, (float) $doc->grand_total);
        $this->assertNotNull($doc->zatca_qr);
        $this->assertSame('standard', $doc->zatca_invoice_type);
        $this->assertEquals($doc->journalEntry->total_debit, $doc->journalEntry->total_credit);
    }

    public function test_scanner_adds_item_line(): void
    {
        $this->seedDemo();
        $item = Item::query()->where('code', '1005')->first();

        $component = Livewire::test(CreateSalesInvoice::class)
            ->set('data.scan', $item->barcode)
            ->set('data.scan', $item->barcode);

        $lines = $component->get('data.lines');
        $this->assertCount(1, $lines);
        $this->assertEquals(2, (float) array_values($lines)[0]['quantity']);
        $this->assertEquals(round(2 * 29 * 1.15, 2), (float) $component->get('data.grand_total'));
    }
}
