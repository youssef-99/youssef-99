<?php

namespace Tests\Feature\Purchases;

use App\Exceptions\ErpException;
use App\Filament\Resources\Purchases\PurchaseInvoiceResource\Pages\CreatePurchaseInvoice;
use App\Models\ItemSerialNumber;
use App\Models\PurchaseDocument;
use App\Services\PurchaseDocumentService;
use App\Services\StockService;
use Livewire\Livewire;
use Tests\ErpTestCase;
use Tests\Feature\Purchases\Concerns\CreatesPurchaseFixtures;

class PurchaseInvoicePostingTest extends ErpTestCase
{
    use CreatesPurchaseFixtures;

    public function test_invoice_created_through_the_form_posts_inventory_vat_and_supplier(): void
    {
        $supplier = $this->makeSupplier(['payment_term_days' => 30]);
        $item = $this->makeItem();

        Livewire::test(CreatePurchaseInvoice::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'account_id' => $supplier->account_id,
                'supplier_invoice_no' => 'S-77',
                'payment_period_days' => 30,
                'post_now' => true,
            ])
            ->set('data.lines', [
                'a' => ['item_id' => $item->id, 'quantity' => 10, 'unit_price' => 20, 'discount_percent' => 10, 'discount2_percent' => 0, 'bonus_qty' => 2, 'bonus_percent' => 0, 'tax_percent' => 15, 'serial_numbers' => ['SN1', 'SN2']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $doc = PurchaseDocument::query()->where('type', 'purchase_invoice')->latest('id')->firstOrFail();
        $this->assertTrue($doc->is_posted);
        $this->assertEquals(200, (float) $doc->gross_total);
        $this->assertEquals(20, (float) $doc->items_discount);
        $this->assertEquals(180, (float) $doc->net_total);
        $this->assertEquals(27, (float) $doc->tax_total);
        $this->assertEquals(207, (float) $doc->grand_total);
        $this->assertSame(now()->addDays(30)->toDateString(), $doc->due_date->toDateString());
        $this->assertEquals(15, (float) $doc->lines->first()->unit_net);

        $entry = $this->entryByAccount($doc->journalEntry);
        $this->assertEquals(180, $entry['110301']);
        $this->assertEquals(27, $entry['110501']);
        $this->assertEquals(-207, $entry[$supplier->account->number]);

        // stock in = quantity + bonus; the bonus lowers the unit cost
        $this->assertEquals(12, StockService::balance($item->id, $doc->warehouse_id));
        $this->assertEquals(15, (float) $item->refresh()->average_cost);
        $this->assertSame(2, ItemSerialNumber::query()->where('item_id', $item->id)->where('status', 'in_stock')->count());
    }

    public function test_moving_average_cost_and_second_discount(): void
    {
        $supplier = $this->makeSupplier();
        $item = $this->makeItem(['vat_rate' => 0]);

        $first = $this->makePurchaseDocument('purchase_invoice', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id], [
            ['item_id' => $item->id, 'quantity' => 10, 'unit_price' => 10, 'tax_percent' => 0],
        ]);
        PurchaseDocumentService::post($first);

        // 10 x 20, 10% then 5% => 200 - 20 - 9 = 171 for 10 units
        $second = $this->makePurchaseDocument('purchase_invoice', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id], [
            ['item_id' => $item->id, 'quantity' => 10, 'unit_price' => 20, 'discount_percent' => 10, 'discount2_percent' => 5, 'tax_percent' => 0],
        ]);
        PurchaseDocumentService::post($second);

        $this->assertEquals(171, (float) $second->net_total);
        $this->assertEquals(round((100 + 171) / 20, 4), (float) $item->refresh()->average_cost);
        $this->assertEquals(20, (float) $item->purchase_price);
        $this->assertEquals(20, StockService::balance($item->id));
    }

    public function test_return_reverses_the_invoice_and_cannot_exceed_received_quantity(): void
    {
        $supplier = $this->makeSupplier();
        $item = $this->makeItem();
        $invoice = $this->makePurchaseDocument('purchase_invoice', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id], [
            ['item_id' => $item->id, 'quantity' => 10, 'unit_price' => 10, 'tax_percent' => 15],
        ]);
        PurchaseDocumentService::post($invoice);

        $tooMuch = $this->makePurchaseDocument('purchase_return', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id, 'reference_type' => 'invoice', 'original_document_id' => $invoice->id], [
            ['item_id' => $item->id, 'quantity' => 11, 'unit_price' => 10, 'tax_percent' => 15],
        ]);
        try {
            PurchaseDocumentService::post($tooMuch);
            $this->fail('A return larger than the invoice was posted.');
        } catch (ErpException $e) {
            $this->assertStringContainsString('10', $e->getMessage());
        }
        $this->assertFalse($tooMuch->refresh()->is_posted);
        $tooMuch->delete();

        $return = $this->makePurchaseDocument('purchase_return', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id, 'reference_type' => 'invoice', 'original_document_id' => $invoice->id], [
            ['item_id' => $item->id, 'quantity' => 4, 'unit_price' => 10, 'tax_percent' => 15],
        ]);
        PurchaseDocumentService::post($return);

        $entry = $this->entryByAccount($return->journalEntry);
        $this->assertEquals(-40, $entry['110301']);
        $this->assertEquals(-6, $entry['110501']);
        $this->assertEquals(46, $entry[$supplier->account->number]);
        $this->assertEquals(6, StockService::balance($item->id));
        $this->assertEquals(6.0, PurchaseDocumentService::returnableQuantities($invoice)[$item->id]);

        $this->expectException(ErpException::class);
        PurchaseDocumentService::unpost($invoice);
    }

    public function test_purchase_order_converts_to_a_draft_invoice(): void
    {
        $supplier = $this->makeSupplier(['payment_term_days' => 60]);
        $item = $this->makeItem();
        $order = $this->makePurchaseDocument('purchase_order', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id], [
            ['item_id' => $item->id, 'quantity' => 5, 'unit_price' => 12, 'tax_percent' => 15, 'bonus_qty' => 1],
        ]);
        PurchaseDocumentService::recalculate($order);

        $invoice = PurchaseDocumentService::convertOrderToInvoice($order);

        $this->assertSame('purchase_invoice', $invoice->type);
        $this->assertSame($order->id, $invoice->purchase_order_id);
        $this->assertFalse($invoice->is_posted);
        $this->assertSame(60, (int) $invoice->payment_period_days);
        $this->assertEquals((float) $order->grand_total, (float) $invoice->grand_total);
        $this->assertEquals(1, (float) $invoice->lines->first()->bonus_qty);
        $this->assertSame('closed', $order->refresh()->status);

        $this->expectException(ErpException::class);
        PurchaseDocumentService::convertOrderToInvoice($order);
    }
}
