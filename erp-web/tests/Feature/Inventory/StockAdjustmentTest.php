<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockDocumentType;
use App\Exceptions\ErpException;
use App\Filament\Resources\Inventory\NegativeAdjustmentResource\Pages\CreateNegativeAdjustment;
use App\Filament\Resources\Inventory\PositiveAdjustmentResource\Pages\CreatePositiveAdjustment;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Services\Settings;
use App\Services\StockDocumentService;
use App\Services\StockService;
use Livewire\Livewire;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\Concerns\CreatesInventory;

class StockAdjustmentTest extends ErpTestCase
{
    use CreatesInventory;

    public function test_positive_adjustment_form_posts_inventory_against_the_adjustment_account(): void
    {
        $item = $this->makeItem('A1');

        Livewire::test(CreatePositiveAdjustment::class)
            ->fillForm([
                'branch_id' => $this->branch()->id,
                'warehouse_id' => $this->mainWarehouse()->id,
                'account_id' => Settings::account('inventory_adjustment'),
                'post_now' => true,
            ])
            ->set('data.lines', ['a' => ['item_id' => $item->id, 'quantity' => 4, 'price' => 12.5, 'discount_percent' => 0]])
            ->call('create')
            ->assertHasNoFormErrors();

        $document = StockDocument::query()->where('type', StockDocumentType::PositiveAdjustment->value)->sole();
        $this->assertTrue($document->is_posted);
        $this->assertEquals(50.00, (float) $document->net_total);

        $movement = StockMovement::query()->where('source_id', $document->id)->sole();
        $this->assertSame('adjust_in', $movement->movement_type);
        $this->assertEquals(4, (float) $movement->qty_in);
        $this->assertEquals(4, StockService::balance($item->id, $this->mainWarehouse()->id));

        $lines = $document->journalEntry->lines;
        $this->assertEquals(50.00, (float) $lines->firstWhere('account_id', Settings::account('inventory'))->debit);
        $this->assertEquals(50.00, (float) $lines->firstWhere('account_id', Settings::account('inventory_adjustment'))->credit);
        $this->assertEquals(12.5, (float) $item->refresh()->average_cost);
    }

    public function test_positive_adjustment_requires_an_account_and_a_warehouse(): void
    {
        Livewire::test(CreatePositiveAdjustment::class)
            ->fillForm(['account_id' => null, 'warehouse_id' => null])
            ->call('create')
            ->assertHasFormErrors(['account_id' => 'required', 'warehouse_id' => 'required']);
    }

    public function test_negative_adjustment_credits_inventory_and_moves_stock_out(): void
    {
        $item = $this->makeItem('A2');
        $this->receive($item, 10, 8);

        Livewire::test(CreateNegativeAdjustment::class)
            ->fillForm([
                'branch_id' => $this->branch()->id,
                'warehouse_id' => $this->mainWarehouse()->id,
                'account_id' => Settings::account('inventory_adjustment'),
                'post_now' => true,
            ])
            ->set('data.lines', ['a' => ['item_id' => $item->id, 'quantity' => 3, 'price' => 8, 'discount_percent' => 0]])
            ->call('create')
            ->assertHasNoFormErrors();

        $document = StockDocument::query()->where('type', StockDocumentType::NegativeAdjustment->value)->sole();
        $this->assertTrue($document->is_posted);
        $this->assertSame('adjust_out', StockMovement::query()->where('source_id', $document->id)->value('movement_type'));
        $this->assertEquals(7, StockService::balance($item->id, $this->mainWarehouse()->id));

        $lines = $document->journalEntry->lines;
        $this->assertEquals(24.00, (float) $lines->firstWhere('account_id', Settings::account('inventory'))->credit);
        $this->assertEquals(24.00, (float) $lines->firstWhere('account_id', Settings::account('inventory_adjustment'))->debit);
    }

    public function test_negative_adjustment_cannot_exceed_the_balance(): void
    {
        $item = $this->makeItem('A3');
        $this->receive($item, 2, 8);
        $document = $this->stockDocument(StockDocumentType::NegativeAdjustment, [['item_id' => $item->id, 'quantity' => 5]]);

        $this->expectException(ErpException::class);
        StockDocumentService::post($document);
    }

    public function test_unposting_an_adjustment_removes_its_movements_and_entry(): void
    {
        $item = $this->makeItem('A4');
        $document = $this->receive($item, 5, 10);
        $entryId = $document->journal_entry_id;

        StockDocumentService::unpost($document);

        $this->assertFalse($document->refresh()->is_posted);
        $this->assertEquals(0, StockService::balance($item->id));
        $this->assertDatabaseMissing('journal_entries', ['id' => $entryId]);
    }
}
