<?php

namespace Tests\Feature\Purchases;

use App\Filament\Resources\Purchases\PurchaseInvoiceResource;
use App\Filament\Resources\Purchases\PurchaseOrderResource\Pages\EditPurchaseOrder;
use App\Filament\Resources\Purchases\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Filament\Resources\Purchases\PurchaseOrderResource\Pages\ViewPurchaseOrder;
use App\Filament\Resources\Purchases\PurchaseReturnResource\Pages\CreatePurchaseReturn;
use App\Models\PurchaseDocument;
use App\Services\PurchaseDocumentService;
use App\Services\StockService;
use Livewire\Livewire;
use Tests\ErpTestCase;
use Tests\Feature\Purchases\Concerns\CreatesPurchaseFixtures;

class PurchaseFormsTest extends ErpTestCase
{
    use CreatesPurchaseFixtures;

    public function test_purchase_order_header_in_modal_lines_after_save_then_convert(): void
    {
        $supplier = $this->makeSupplier();
        $item = $this->makeItem();

        Livewire::test(ListPurchaseOrders::class)
            ->callAction('create', data: ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id, 'notes' => 'urgent'])
            ->assertHasNoActionErrors();

        $order = PurchaseDocument::query()->where('type', 'purchase_order')->firstOrFail();
        $this->assertNotEmpty($order->number);
        $this->assertNotNull($order->warehouse_id);

        Livewire::test(EditPurchaseOrder::class, ['record' => $order->getRouteKey()])
            ->set('data.lines', ['a' => ['item_id' => $item->id, 'quantity' => 4, 'unit_price' => 25, 'tax_percent' => 15, 'discount_percent' => 0, 'discount2_percent' => 0, 'bonus_qty' => 0, 'bonus_percent' => 0]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(115, (float) $order->refresh()->grand_total);

        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('convertToInvoice')
            ->assertRedirect(PurchaseInvoiceResource::getUrl('edit', ['record' => PurchaseDocument::query()->where('purchase_order_id', $order->id)->value('id')]));

        $this->assertSame('closed', $order->refresh()->status);
    }

    public function test_return_form_loads_the_returnable_lines_of_the_original_invoice(): void
    {
        $supplier = $this->makeSupplier();
        $item = $this->makeItem();
        $invoice = $this->makePurchaseDocument('purchase_invoice', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id], [
            ['item_id' => $item->id, 'quantity' => 8, 'unit_price' => 10, 'tax_percent' => 15],
        ]);
        PurchaseDocumentService::post($invoice);

        $component = Livewire::test(CreatePurchaseReturn::class)
            ->fillForm(['supplier_id' => $supplier->id, 'reference_type' => 'invoice'])
            ->set('data.original_document_id', $invoice->id);

        $lines = array_values($component->get('data.lines'));
        $this->assertCount(1, $lines);
        $this->assertEquals(8, (float) $lines[0]['quantity']);
        $this->assertEquals(92, (float) $component->get('data.grand_total'));

        $component->fillForm(['post_now' => true])->call('create')->assertHasNoFormErrors();

        $return = PurchaseDocument::query()->where('type', 'purchase_return')->firstOrFail();
        $this->assertTrue($return->is_posted);
        $this->assertSame($supplier->account_id, $return->account_id);
        $this->assertEquals(0, StockService::balance($item->id));
    }

    public function test_return_requires_reference_type_and_original_invoice(): void
    {
        Livewire::test(CreatePurchaseReturn::class)
            ->fillForm(['reference_type' => 'invoice', 'original_document_id' => null])
            ->call('create')
            ->assertHasFormErrors(['original_document_id' => 'required', 'supplier_id' => 'required']);
    }
}
