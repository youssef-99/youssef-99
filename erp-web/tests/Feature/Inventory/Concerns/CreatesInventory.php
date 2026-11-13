<?php

namespace Tests\Feature\Inventory\Concerns;

use App\Enums\StockDocumentType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockDocument;
use App\Models\Warehouse;
use App\Services\StockDocumentService;

/**
 * Minimal inventory fixtures: a category, items and stock brought in with a positive adjustment.
 */
trait CreatesInventory
{
    protected function branch(): Branch
    {
        return Branch::query()->firstOrFail();
    }

    protected function mainWarehouse(): Warehouse
    {
        return Warehouse::query()->orderBy('id')->firstOrFail();
    }

    protected function secondWarehouse(): Warehouse
    {
        return Warehouse::query()->firstOrCreate(['code' => 'T2'], ['branch_id' => $this->branch()->id, 'name_ar' => 'مستودع الاختبار', 'name_en' => 'Test Store']);
    }

    protected function category(): ItemCategory
    {
        return ItemCategory::query()->firstOrCreate(['code' => 'T1'], ['name_ar' => 'أدوية', 'name_en' => 'Medicines']);
    }

    /** @param array<string, mixed> $attributes */
    protected function makeItem(string $code, array $attributes = []): Item
    {
        return Item::query()->create($attributes + [
            'code' => $code,
            'name_ar' => 'صنف '.$code,
            'name_en' => 'Item '.$code,
            'category_id' => $this->category()->id,
            'item_type' => 'standard',
            'purchase_price' => 10,
            'sale_price' => 15,
            'vat_rate' => 15,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $attributes
     */
    protected function stockDocument(StockDocumentType $type, array $lines, array $attributes = []): StockDocument
    {
        $document = StockDocument::query()->create($attributes + [
            'type' => $type->value,
            'branch_id' => $this->branch()->id,
            'warehouse_id' => $this->mainWarehouse()->id,
            'date' => now()->toDateString(),
        ]);
        foreach ($lines as $line) {
            $document->lines()->create($line);
        }

        return StockDocumentService::recalculate($document->refresh());
    }

    /** Bring stock in at the given unit cost (posted positive adjustment). */
    protected function receive(Item $item, float $qty, float $cost, ?int $warehouseId = null, ?string $date = null, array $line = []): StockDocument
    {
        $document = $this->stockDocument(StockDocumentType::PositiveAdjustment, [$line + ['item_id' => $item->id, 'quantity' => $qty, 'price' => $cost]], [
            'warehouse_id' => $warehouseId ?? $this->mainWarehouse()->id,
            'date' => $date ?? now()->toDateString(),
        ]);

        return StockDocumentService::post($document);
    }
}
