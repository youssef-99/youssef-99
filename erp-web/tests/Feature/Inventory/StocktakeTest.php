<?php

namespace Tests\Feature\Inventory;

use App\Enums\StockDocumentType;
use App\Exceptions\ErpException;
use App\Filament\Pages\Inventory\PeriodicInventoryApproval;
use App\Filament\Resources\Inventory\AnnualStocktakeResource\Pages\CreateAnnualStocktake;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Services\Settings;
use App\Services\StockDocumentService;
use App\Services\StockService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\Concerns\CreatesInventory;

class StocktakeTest extends ErpTestCase
{
    use CreatesInventory;

    public function test_annual_stocktake_books_surplus_and_shortage_against_the_book_balance(): void
    {
        $surplusItem = $this->makeItem('S1');
        $shortageItem = $this->makeItem('S2');
        $this->receive($surplusItem, 10, 5, date: now()->subDay()->toDateString());
        $this->receive($shortageItem, 10, 4, date: now()->subDay()->toDateString());

        Livewire::test(CreateAnnualStocktake::class)
            ->fillForm([
                'branch_id' => $this->branch()->id,
                'warehouse_id' => $this->mainWarehouse()->id,
                'account_id' => Settings::account('inventory_adjustment'),
                'post_now' => true,
            ])
            ->set('data.lines', [
                'a' => ['item_id' => $surplusItem->id, 'quantity' => 12, 'price' => 5, 'discount_percent' => 0],
                'b' => ['item_id' => $shortageItem->id, 'quantity' => 7, 'price' => 4, 'discount_percent' => 0],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $document = StockDocument::query()->where('type', StockDocumentType::AnnualStocktake->value)->sole();
        $this->assertTrue($document->is_posted);
        $this->assertEquals([10.0, 10.0], $document->lines->map(fn ($l) => (float) $l->system_qty)->all());
        $this->assertEquals(12, StockService::balance($surplusItem->id));
        $this->assertEquals(7, StockService::balance($shortageItem->id));
        $this->assertSame(2, StockMovement::query()->where('source_id', $document->id)->where('movement_type', 'stocktake')->count());

        // surplus 2 x 5 = 10, shortage 3 x 4 = 12 -> net shortage 2
        $lines = $document->journalEntry->lines;
        $this->assertEquals(2.00, (float) $lines->firstWhere('account_id', Settings::account('inventory'))->credit);
        $this->assertEquals(2.00, (float) $lines->firstWhere('account_id', Settings::account('inventory_adjustment'))->debit);
    }

    public function test_load_items_fills_the_grid_with_book_balances(): void
    {
        $item = $this->makeItem('S3');
        $this->receive($item, 6, 5);

        $component = Livewire::test(CreateAnnualStocktake::class)
            ->fillForm(['warehouse_id' => $this->mainWarehouse()->id])
            ->callAction(TestAction::make('loadItems')->schemaComponent('stocktakeActions', 'form'))
            ->assertHasNoErrors();

        $line = collect($component->get('data.lines'))->sole();
        $this->assertSame($item->id, $line['item_id']);
        $this->assertEquals(6, $line['system_qty']);
        $this->assertEquals(6, $line['quantity']);
    }

    public function test_periodic_stocktake_must_be_approved_before_posting(): void
    {
        $item = $this->makeItem('S4');
        $this->receive($item, 10, 5);
        $document = $this->stockDocument(StockDocumentType::PeriodicStocktake, [['item_id' => $item->id, 'quantity' => 9]]);

        try {
            StockDocumentService::post($document);
            $this->fail('An unapproved periodic stocktake was posted.');
        } catch (ErpException) {
            $this->assertFalse($document->refresh()->is_posted);
        }

        Livewire::test(PeriodicInventoryApproval::class)
            ->assertCanSeeTableRecords([$document])
            ->callTableAction('approve', $document)
            ->assertNotified();

        $document->refresh();
        $this->assertTrue($document->is_approved);
        $this->assertTrue($document->is_posted);
        $this->assertEquals(9, StockService::balance($item->id));
        $this->assertEquals(5.00, (float) $document->journalEntry->lines->firstWhere('account_id', Settings::account('inventory_adjustment'))->debit);
    }
}
