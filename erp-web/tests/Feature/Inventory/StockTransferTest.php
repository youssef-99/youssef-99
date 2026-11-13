<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockDocumentType;
use App\Exceptions\ErpException;
use App\Filament\Resources\Inventory\InboundTransferResource\Pages\EditInboundTransfer;
use App\Filament\Resources\Inventory\OutboundTransferResource\Pages\CreateOutboundTransfer;
use App\Models\StockDocument;
use App\Services\StockDocumentService;
use App\Services\StockService;
use Livewire\Livewire;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\Concerns\CreatesInventory;

class StockTransferTest extends ErpTestCase
{
    use CreatesInventory;

    public function test_outbound_transfer_moves_stock_out_and_creates_the_inbound_draft(): void
    {
        $item = $this->makeItem('T1', ['item_type' => 'batch_expiry']);
        $this->receive($item, 10, 6, line: ['batch_no' => 'B1', 'expiry_date' => now()->addYear()->startOfMonth()->toDateString()]);
        $batchId = $item->batches()->value('id');
        $source = $this->mainWarehouse();
        $destination = $this->secondWarehouse();

        Livewire::test(CreateOutboundTransfer::class)
            ->fillForm([
                'branch_id' => $this->branch()->id,
                'warehouse_id' => $source->id,
                'to_branch_id' => $this->branch()->id,
                'to_warehouse_id' => $destination->id,
                'post_now' => true,
            ])
            ->set('data.lines', ['a' => ['item_id' => $item->id, 'item_batch_id' => $batchId, 'quantity' => 4, 'price' => 6, 'discount_percent' => 0]])
            ->call('create')
            ->assertHasNoFormErrors();

        $outbound = StockDocument::query()->where('type', StockDocumentType::OutboundTransfer->value)->sole();
        $this->assertTrue($outbound->is_posted);
        $this->assertNull($outbound->journal_entry_id);
        $this->assertEquals(6, StockService::balance($item->id, $source->id, $batchId));
        $this->assertEquals(0, StockService::balance($item->id, $destination->id));

        $inbound = StockDocument::query()->where('type', StockDocumentType::InboundTransfer->value)->sole();
        $this->assertFalse($inbound->is_posted);
        $this->assertSame($outbound->id, $inbound->related_document_id);
        $this->assertSame($destination->id, $inbound->warehouse_id);
        $this->assertSame($source->id, $inbound->to_warehouse_id);
        $this->assertEquals(4, (float) $inbound->lines->sole()->quantity);
        $this->assertSame($batchId, $inbound->lines->sole()->item_batch_id);

        Livewire::test(EditInboundTransfer::class, ['record' => $inbound->getRouteKey()])
            ->fillForm(['post_now' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($inbound->refresh()->is_posted);
        $this->assertCount(1, $inbound->lines()->get());
        $this->assertEquals(4, StockService::balance($item->id, $destination->id, $batchId));
        $this->assertEquals(10, StockService::balance($item->id));
        $this->assertEquals(6, (float) $item->refresh()->average_cost);

        $this->expectException(ErpException::class);
        StockDocumentService::unpost($outbound->refresh());
    }

    public function test_unposting_an_outbound_transfer_deletes_the_unreceived_inbound_draft(): void
    {
        $item = $this->makeItem('T2');
        $this->receive($item, 5, 6);
        $outbound = StockDocumentService::post($this->stockDocument(StockDocumentType::OutboundTransfer, [['item_id' => $item->id, 'quantity' => 2]], [
            'to_branch_id' => $this->branch()->id, 'to_warehouse_id' => $this->secondWarehouse()->id,
        ]));

        StockDocumentService::unpost($outbound);

        $this->assertDatabaseMissing('stock_documents', ['type' => StockDocumentType::InboundTransfer->value]);
        $this->assertEquals(5, StockService::balance($item->id, $this->mainWarehouse()->id));
    }

    public function test_transfer_to_the_same_warehouse_is_rejected(): void
    {
        $item = $this->makeItem('T3');
        $this->receive($item, 5, 6);

        Livewire::test(CreateOutboundTransfer::class)
            ->fillForm([
                'branch_id' => $this->branch()->id,
                'warehouse_id' => $this->mainWarehouse()->id,
                'to_branch_id' => $this->branch()->id,
                'to_warehouse_id' => $this->mainWarehouse()->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['to_warehouse_id' => 'different']);

        $document = $this->stockDocument(StockDocumentType::OutboundTransfer, [['item_id' => $item->id, 'quantity' => 1]], [
            'to_warehouse_id' => $this->mainWarehouse()->id,
        ]);
        $this->expectException(ErpException::class);
        StockDocumentService::post($document);
    }
}
