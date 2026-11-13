<?php

namespace Tests\Feature\Inventory;

use App\Filament\Pages\Inventory\ActionsForItems;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PurchaseDocument;
use App\Services\ItemActionsService;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\Concerns\CreatesInventory;

class ItemActionsTest extends ErpTestCase
{
    use CreatesInventory;

    public function test_selling_price_update_by_percent_is_limited_to_the_category_and_its_children(): void
    {
        $child = ItemCategory::query()->create(['code' => 'T1-1', 'name_ar' => 'فرعي', 'parent_id' => $this->category()->id]);
        $other = ItemCategory::query()->create(['code' => 'X', 'name_ar' => 'أخرى']);
        $inChild = $this->makeItem('P1', ['category_id' => $child->id, 'sale_price' => 100]);
        $outside = $this->makeItem('P2', ['category_id' => $other->id, 'sale_price' => 100]);
        $this->receive($inChild, 1, 50, line: []);
        $inChild->batches()->create(['batch_no' => 'B', 'sale_price' => 40]);

        Livewire::test(ActionsForItems::class)
            ->callAction('updateSalePrice', data: ['category_id' => $this->category()->id, 'percent' => 10, 'include_batches' => true])
            ->assertHasNoActionErrors();

        $this->assertEquals(110, (float) $inChild->refresh()->sale_price);
        $this->assertEquals(44, (float) $inChild->batches()->value('sale_price'));
        $this->assertEquals(100, (float) $outside->refresh()->sale_price);
    }

    public function test_purchase_price_is_taken_from_the_last_posted_purchase(): void
    {
        $item = $this->makeItem('P3', ['purchase_price' => 10]);
        foreach ([[now()->subDays(5), 11, true], [now()->subDays(2), 13, true], [now()->subDay(), 99, false]] as [$date, $price, $posted]) {
            $purchase = PurchaseDocument::query()->create([
                'type' => 'purchase_invoice', 'branch_id' => $this->branch()->id, 'warehouse_id' => $this->mainWarehouse()->id, 'date' => $date,
            ]);
            $purchase->lines()->create(['item_id' => $item->id, 'quantity' => 1, 'unit_price' => $price]);
            $purchase->forceFill(['is_posted' => $posted])->saveQuietly();
        }

        $updated = ItemActionsService::updatePurchasePriceFromLastPurchase(Item::query()->whereKey($item->id));

        $this->assertSame(1, $updated);
        $this->assertEquals(13, (float) $item->refresh()->purchase_price);
    }

    public function test_deactivate_selected_items(): void
    {
        $first = $this->makeItem('P4');
        $second = $this->makeItem('P5');

        Livewire::test(ActionsForItems::class)
            ->callTableBulkAction('deactivateSelected', [$first])
            ->assertHasNoTableBulkActionErrors();

        $this->assertFalse($first->refresh()->is_active);
        $this->assertTrue($second->refresh()->is_active);
    }

    public function test_send_to_epos_exports_active_items_and_flags_them(): void
    {
        $active = $this->makeItem('P6', ['barcode' => '111']);
        $inactive = $this->makeItem('P7', ['is_active' => false]);

        $export = ItemActionsService::exportToEpos(Item::query()->where('is_active', true));

        $reader = new Reader;
        $reader->open($export['path']);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        unlink($export['path']);

        $this->assertSame(1, $export['count']);
        $this->assertSame(['code', 'barcode'], array_slice($rows[0], 0, 2));
        $this->assertSame(['P6', '111'], array_slice($rows[1], 0, 2));
        $this->assertTrue($active->refresh()->sent_to_epos);
        $this->assertFalse($inactive->refresh()->sent_to_epos);
    }

    public function test_send_to_epos_action_downloads_a_file(): void
    {
        $this->makeItem('P8');

        Livewire::test(ActionsForItems::class)
            ->callAction('sendToEpos', data: ['format' => 'csv'])
            ->assertFileDownloaded();
    }

    public function test_recalculate_batch_balances_rebuilds_average_cost_and_last_purchase_price(): void
    {
        $item = $this->makeItem('P9', ['item_type' => 'batch_expiry']);
        $this->receive($item, 10, 10, line: ['batch_no' => 'B1']);
        $this->receive($item, 10, 20, line: ['batch_no' => 'B1']);
        $item->forceFill(['average_cost' => 1])->saveQuietly();

        $result = ItemActionsService::recalculateBatchBalances(Item::query()->whereKey($item->id));

        $this->assertSame(['items' => 1, 'batches' => 1, 'negative_batches' => 0], $result);
        $this->assertEquals(15, (float) $item->refresh()->average_cost);
        $this->assertEquals(20, (float) $item->batches()->value('last_purchase_price'));
    }
}
