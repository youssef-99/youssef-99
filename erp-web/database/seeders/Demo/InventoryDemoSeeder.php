<?php

namespace Database\Seeders\Demo;

use App\Enums\StockDocumentType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBalanceLimit;
use App\Models\ItemGroup;
use App\Models\ItemSerialNumber;
use App\Models\StockDocument;
use App\Models\Warehouse;
use App\Services\StockDocumentService;
use Illuminate\Database\Seeder;

/**
 * Inventory demo data: an item group, serial numbers, balance limits and one document of each stock type.
 */
class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (StockDocument::query()->exists()) {
            return;
        }

        $branch = Branch::query()->first();
        $warehouse = Warehouse::query()->where('branch_id', $branch->id)->orderBy('id')->first();
        $store = Warehouse::query()->firstOrCreate(['code' => '2'], ['branch_id' => $branch->id, 'name_ar' => 'مستودع الصيدلية', 'name_en' => 'Pharmacy Store']);
        $items = Item::query()->get()->keyBy('code');

        if ($items->isEmpty() || ! $warehouse) {
            return;
        }

        $group = ItemGroup::query()->firstOrCreate(['code' => '1'], ['name_ar' => 'مسكنات', 'name_en' => 'Painkillers']);
        Item::query()->whereIn('code', ['1001', '1002'])->update(['group_id' => $group->id]);

        if ($monitor = $items->get('1007')) {
            foreach (['BPM-0001', 'BPM-0002', 'BPM-0003'] as $serial) {
                ItemSerialNumber::query()->firstOrCreate(['item_id' => $monitor->id, 'serial_no' => $serial], ['warehouse_id' => $warehouse->id]);
            }
        }

        foreach (['1001' => [20, 200, 50], '1005' => [10, 100, 30]] as $code => [$min, $max, $reorder]) {
            if ($item = $items->get($code)) {
                ItemBalanceLimit::query()->firstOrCreate(
                    ['item_id' => $item->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id],
                    ['min_qty' => $min, 'max_qty' => $max, 'reorder_qty' => $reorder],
                );
            }
        }

        $base = ['branch_id' => $branch->id, 'warehouse_id' => $warehouse->id];
        $line = fn (string $code, float $qty) => [
            'item_id' => $items[$code]->id,
            'unit_id' => $items[$code]->unit_id,
            'item_batch_id' => $items[$code]->batches()->value('id'),
            'quantity' => $qty,
        ];

        // Draft documents first: their edit pages render in the smoke test.
        $this->document(StockDocumentType::NegativeAdjustment, $base + ['date' => now()->subDays(1), 'notes' => 'تالف'], [$line('1006', 2)]);
        $this->document(StockDocumentType::AnnualStocktake, $base + ['date' => now()], [$line('1005', 98) + ['system_qty' => 0]]);
        $this->document(StockDocumentType::PeriodicStocktake, $base + ['date' => now(), 'supplier_id' => $items['1001']->supplier_id], [$line('1001', 80)]);

        $positive = $this->document(StockDocumentType::PositiveAdjustment, $base + ['date' => now()->subDays(2)], [$line('1005', 5)]);
        StockDocumentService::post($positive);

        $outbound = $this->document(StockDocumentType::OutboundTransfer, $base + [
            'date' => now()->subDays(1), 'to_branch_id' => $branch->id, 'to_warehouse_id' => $store->id,
        ], [$line('1002', 5), $line('1006', 10)]);
        StockDocumentService::post($outbound);
    }

    /** @param list<array<string, mixed>> $lines */
    protected function document(StockDocumentType $type, array $attributes, array $lines): StockDocument
    {
        $document = StockDocument::query()->create(['type' => $type->value] + $attributes);
        foreach ($lines as $line) {
            $document->lines()->create($line);
        }

        return StockDocumentService::recalculate($document->refresh());
    }
}
