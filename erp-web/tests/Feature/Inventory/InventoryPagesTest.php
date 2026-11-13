<?php

namespace Tests\Feature\Inventory;

use App\Enums\Module;
use App\Filament\Resources\Inventory\ItemCategoryResource\Pages\ManageItemCategories;
use App\Filament\Resources\Inventory\ItemResource\Pages\ManageItems;
use App\Models\Item;
use App\Models\ItemCategory;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\Concerns\CreatesInventory;

class InventoryPagesTest extends ErpTestCase
{
    use CreatesInventory;

    public function test_every_inventory_page_renders_with_demo_data(): void
    {
        $this->seedDemo();
        $panel = Filament::getPanel('admin');
        $checked = 0;

        foreach ($panel->getResources() as $resource) {
            if (! method_exists($resource, 'getModule') || $resource::getModule() !== Module::Inventory) {
                continue;
            }
            foreach (['index', 'create'] as $page) {
                if ($resource::hasPage($page)) {
                    $this->get($resource::getUrl($page))->assertOk();
                    $checked++;
                }
            }
            if ($record = $resource::getEloquentQuery()->first()) {
                foreach (['view', 'edit'] as $page) {
                    if ($resource::hasPage($page)) {
                        $this->get($resource::getUrl($page, ['record' => $record]))->assertOk();
                        $checked++;
                    }
                }
            }
        }

        foreach ($panel->getPages() as $page) {
            if (str_starts_with($page, 'App\\Filament\\Pages\\Inventory\\')) {
                $this->get($page::getUrl())->assertOk();
                $checked++;
            }
        }

        $this->assertGreaterThan(30, $checked);
    }

    public function test_add_item_modal_creates_an_item(): void
    {
        Livewire::test(ManageItems::class)
            ->callAction('create', data: [
                'category_id' => $this->category()->id,
                'code' => 'N1',
                'reference_no' => 'REF-1',
                'name_ar' => 'صنف جديد',
                'item_type' => 'batch_expiry',
                'item_nature' => 'stock',
                'vat_rate' => 15,
                'sale_price' => 20,
                'purchase_price' => 12,
            ])
            ->assertHasNoActionErrors();

        $item = Item::query()->where('code', 'N1')->sole();
        $this->assertSame('REF-1', $item->reference_no);
        $this->assertNull($item->tax_exemption_reason);
    }

    public function test_zero_vat_item_requires_an_exemption_reason(): void
    {
        Livewire::test(ManageItems::class)
            ->callAction('create', data: [
                'category_id' => $this->category()->id,
                'code' => 'N2',
                'reference_no' => 'REF-2',
                'name_ar' => 'دواء معفى',
                'item_type' => 'batch_expiry',
                'item_nature' => 'stock',
                'vat_rate' => 0,
                'tax_exemption_reason' => null,
            ])
            ->assertHasActionErrors(['tax_exemption_reason' => 'required']);

        $this->assertDatabaseMissing('items', ['code' => 'N2']);
    }

    public function test_add_items_action_imports_an_uploaded_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('items.csv', "code,barcode,name_ar,name_en,unit,sale price,purchase price,vat rate\nU1,999,صنف مستورد,Imported,,10,6,15\n");

        Livewire::test(ManageItems::class)
            ->callAction(TestAction::make('importItems'), data: [
                'file' => $file,
                'category_id' => $this->category()->id,
                'item_type' => 'standard',
                'tax_exemption_reason' => 'VATEX-SA-35',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas('items', ['code' => 'U1', 'barcode' => '999', 'category_id' => $this->category()->id]);
    }

    public function test_balance_modal_lists_balances_per_warehouse_and_batch(): void
    {
        $item = $this->makeItem('BAL', ['item_type' => 'batch_expiry']);
        $this->receive($item, 7, 5, line: ['batch_no' => 'LOT-77', 'expiry_date' => now()->addYear()->startOfMonth()->toDateString()]);

        Livewire::test(ManageItems::class)
            ->mountAction(TestAction::make('viewBalance')->table($item))
            ->assertMountedActionModalSee([$this->mainWarehouse()->name_ar, 'LOT-77']);
    }

    public function test_item_category_modal_saves_points_and_flags(): void
    {
        Livewire::test(ManageItemCategories::class)
            ->callAction('create', data: [
                'code' => 'K1', 'name_ar' => 'تجميل', 'points_percent' => 3, 'is_sales_category' => false, 'is_active' => true,
                'parent_id' => $this->category()->id,
            ])
            ->assertHasNoActionErrors();

        $category = ItemCategory::query()->where('code', 'K1')->sole();
        $this->assertEquals(3, (float) $category->points_percent);
        $this->assertFalse($category->is_sales_category);
        $this->assertSame($this->category()->id, $category->parent_id);
    }
}
