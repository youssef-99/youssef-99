<?php

namespace Tests\Feature\Purchases\Concerns;

use App\Models\Account;
use App\Models\Branch;
use App\Models\City;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\JournalEntry;
use App\Models\PurchaseDocument;
use App\Models\Supplier;
use App\Models\SupplierGroup;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\DocumentNumber;
use App\Services\SupplierService;

/**
 * Small fixtures for purchase tests (the project has no factories for these models).
 */
trait CreatesPurchaseFixtures
{
    protected function makeSupplier(array $attributes = []): Supplier
    {
        $city = City::query()->firstOrFail();
        $supplier = Supplier::query()->create($attributes + [
            'code' => DocumentNumber::nextCode(Supplier::class),
            'name_ar' => 'مورد اختبار',
            'supplier_group_id' => SupplierGroup::query()->firstOrCreate(['code' => 'T1'], ['name_ar' => 'مجموعة اختبار'])->id,
            'country_id' => $city->country_id,
            'city_id' => $city->id,
        ]);

        return SupplierService::afterSave($supplier);
    }

    protected function makeItem(array $attributes = []): Item
    {
        return Item::query()->create($attributes + [
            'code' => DocumentNumber::nextCode(Item::class, start: 9001),
            'name_ar' => 'صنف اختبار',
            'category_id' => ItemCategory::query()->firstOrCreate(['code' => 'T1'], ['name_ar' => 'فئة اختبار'])->id,
            'unit_id' => Unit::query()->value('id'),
            'item_type' => 'standard',
            'vat_rate' => 15,
            'gtin' => '06281000009999',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function makePurchaseDocument(string $type, array $attributes, array $lines): PurchaseDocument
    {
        $branch = Branch::query()->firstOrFail();
        $doc = PurchaseDocument::query()->create($attributes + [
            'type' => $type,
            'branch_id' => $branch->id,
            'warehouse_id' => Warehouse::query()->where('branch_id', $branch->id)->value('id'),
            'date' => now()->toDateString(),
        ]);
        foreach ($lines as $line) {
            $doc->lines()->create($line);
        }

        return $doc;
    }

    /** @return array<string, float> account number => debit - credit */
    protected function entryByAccount(?JournalEntry $entry): array
    {
        $this->assertNotNull($entry);
        $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);

        return $entry->lines()->get()->groupBy(fn ($l) => Account::query()->whereKey($l->account_id)->value('number'))
            ->map(fn ($lines) => round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 2))
            ->all();
    }
}
