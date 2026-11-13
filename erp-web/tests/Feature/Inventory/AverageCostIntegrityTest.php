<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockDocumentType;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Services\Settings;
use App\Services\StockDocumentService;
use App\Services\StockService;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\Concerns\CreatesInventory;

/**
 * Moving average cost stays consistent across unposting, transfers and stale draft prices.
 */
class AverageCostIntegrityTest extends ErpTestCase
{
    use CreatesInventory;

    public function test_unposting_a_receipt_restores_the_previous_average_cost(): void
    {
        $item = $this->makeItem('AC1');
        $this->receive($item, 10, 10);
        $second = $this->receive($item, 10, 20);
        $this->assertEquals(15, (float) $item->refresh()->average_cost);

        StockDocumentService::unpost($second);
        $this->assertEquals(10, (float) $item->refresh()->average_cost);

        StockDocumentService::post($second->refresh());
        StockDocumentService::unpost($second->refresh());
        StockDocumentService::post($second->refresh());
        $this->assertEquals(15, (float) $item->refresh()->average_cost, 'post / unpost / post must not compound the cost.');
    }

    public function test_unposting_the_only_receipt_resets_the_average_cost(): void
    {
        $item = $this->makeItem('AC2');
        $receipt = $this->receive($item, 4, 12.5);

        StockDocumentService::unpost($receipt);

        $this->assertEquals(0, (float) $item->refresh()->average_cost);
    }

    public function test_transfers_do_not_change_the_average_cost(): void
    {
        $item = $this->makeItem('AC3');
        $this->receive($item, 10, 6);

        $outbound = StockDocumentService::post($this->stockDocument(StockDocumentType::OutboundTransfer, [['item_id' => $item->id, 'quantity' => 4, 'price' => 6]], [
            'to_branch_id' => $this->branch()->id, 'to_warehouse_id' => $this->secondWarehouse()->id,
        ]));
        $inbound = StockDocument::query()->where('related_document_id', $outbound->id)->sole();
        $inbound->lines()->update(['price' => 50]);
        StockDocumentService::post($inbound->refresh());

        $this->assertEquals(6, (float) $item->refresh()->average_cost);
        $this->assertEquals(6, StockService::replayAverageCost($item->id));
        $this->assertEquals(10, StockService::balance($item->id));
    }

    public function test_negative_adjustment_is_valued_at_the_current_average_cost_when_posted(): void
    {
        $item = $this->makeItem('AC4');
        $this->receive($item, 10, 8);
        $draft = $this->stockDocument(StockDocumentType::NegativeAdjustment, [['item_id' => $item->id, 'quantity' => 2]], [
            'account_id' => Settings::account('inventory_adjustment'),
        ]);
        $this->assertEquals(8, (float) $draft->lines->sole()->price);

        $this->receive($item, 10, 12);
        StockDocumentService::post($draft->refresh());

        $this->assertEquals(10, (float) $draft->lines()->sole()->price);
        $movement = StockMovement::query()->where('source_type', $draft->getMorphClass())->where('source_id', $draft->id)->sole();
        $this->assertEquals(10, (float) $movement->unit_cost);
        $this->assertEquals(20.00, (float) $draft->refresh()->journalEntry->lines->firstWhere('account_id', Settings::account('inventory'))->credit);
    }

    public function test_stocktake_shortage_is_valued_at_the_current_average_cost(): void
    {
        $item = $this->makeItem('AC5');
        $this->receive($item, 10, 4, date: now()->subDays(2)->toDateString());
        $stocktake = $this->stockDocument(StockDocumentType::AnnualStocktake, [['item_id' => $item->id, 'quantity' => 7, 'price' => 1]], [
            'account_id' => Settings::account('inventory_adjustment'),
        ]);

        StockDocumentService::post($stocktake->refresh());

        $this->assertEquals(12.00, (float) $stocktake->refresh()->journalEntry->lines->firstWhere('account_id', Settings::account('inventory'))->credit);
    }
}
