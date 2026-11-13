<?php

namespace Tests\Feature\Purchases;

use App\Exceptions\ErpException;
use App\Filament\Resources\Purchases\PurchaseInvoiceResource\Pages\ListPurchaseInvoices;
use App\Services\PurchaseDocumentService;
use App\Services\RasdService;
use Livewire\Livewire;
use Tests\ErpTestCase;
use Tests\Feature\Purchases\Concerns\CreatesPurchaseFixtures;

class RasdTest extends ErpTestCase
{
    use CreatesPurchaseFixtures;

    public function test_rasd_file_lists_gtin_batch_expiry_quantity_and_serials(): void
    {
        $supplier = $this->makeSupplier(['vat_no' => '300000000000003']);
        $item = $this->makeItem(['item_type' => 'batch_expiry', 'gtin' => '6281000000123']);
        $doc = $this->makePurchaseDocument('purchase_invoice', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id, 'supplier_invoice_no' => 'SUP-1'], [
            ['item_id' => $item->id, 'quantity' => 10, 'bonus_qty' => 2, 'unit_price' => 5, 'tax_percent' => 15, 'batch_no' => 'B77', 'expiry_date' => '2028-03-01', 'serial_numbers' => ['S-1', 'S-2']],
        ]);

        try {
            RasdService::prepare($doc);
            $this->fail('An unposted invoice was prepared for Rasd.');
        } catch (ErpException) {
            $this->assertNull($doc->refresh()->rasd_status);
        }

        PurchaseDocumentService::post($doc);
        RasdService::prepare($doc);

        $this->assertSame('prepared', $doc->rasd_status);
        $xml = simplexml_load_string($doc->rasd_file);
        $this->assertSame('ACCEPT', (string) $xml['type']);
        $this->assertSame('SUP-1', (string) $xml->Header->SupplierInvoiceNumber);
        $this->assertSame('300000000000003', (string) $xml->Header->SupplierVatNumber);
        $product = $xml->Products->Product[0];
        $this->assertSame('06281000000123', (string) $product->GTIN);
        $this->assertSame('B77', (string) $product->BatchNumber);
        $this->assertSame('2028-03-01', (string) $product->ExpiryDate);
        $this->assertSame('12', (string) $product->Quantity);
        $this->assertSame(['S-1', 'S-2'], array_map('strval', iterator_to_array($product->SerialNumbers->SerialNumber, false)));

        RasdService::send($doc);
        $this->assertSame('sent', $doc->refresh()->rasd_status);
        $this->assertNotNull($doc->rasd_sent_at);
        $this->assertStringStartsWith('RSD-SANDBOX-', $doc->rasd_reference);
        $this->assertSame('ACCEPTED', json_decode($doc->rasd_response, true)['status']);
    }

    public function test_rasd_row_actions(): void
    {
        $supplier = $this->makeSupplier();
        $item = $this->makeItem();
        $doc = $this->makePurchaseDocument('purchase_invoice', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id], [
            ['item_id' => $item->id, 'quantity' => 3, 'unit_price' => 5, 'tax_percent' => 15],
        ]);
        PurchaseDocumentService::post($doc);

        Livewire::test(ListPurchaseInvoices::class)
            ->callTableAction('prepareRasdFile', $doc)
            ->assertFileDownloaded(RasdService::fileName($doc));
        $this->assertSame('prepared', $doc->refresh()->rasd_status);

        Livewire::test(ListPurchaseInvoices::class)->callTableAction('sendToRasd', $doc);
        $this->assertSame('sent', $doc->refresh()->rasd_status);
    }
}
