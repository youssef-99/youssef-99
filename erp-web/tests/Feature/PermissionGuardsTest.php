<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Filament\Pages\General\SystemSettings;
use App\Filament\Pages\Inventory\ActionsForItems;
use App\Filament\Pages\Inventory\InvoiceItemsCost;
use App\Filament\Resources\Sales\SalesInvoiceResource\Pages\CreateSalesInvoice;
use App\Models\Customer;
use App\Models\Group;
use App\Models\Item;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\SafeSpreadsheetRow;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell\StringCell;
use Tests\ErpTestCase;

/**
 * Cross-module authorization guards: "post now" needs Post, tool pages need more than View, exports are formula-safe.
 */
class PermissionGuardsTest extends ErpTestCase
{
    /**
     * @param  array<int, string>  $permissions
     */
    private function actAsUserWith(array $permissions): User
    {
        $group = Group::query()->create(['code' => '77', 'name_ar' => 'مجموعة اختبار', 'permissions' => $permissions]);
        $user = User::factory()->create(['group_id' => $group->id, 'is_admin' => false, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_post_now_is_ignored_without_post_permission(): void
    {
        $this->seedDemo();
        $customer = Customer::query()->first();
        $item = Item::query()->where('code', '1002')->first();
        $this->actAsUserWith(['sales.sales-invoices.view', 'sales.sales-invoices.create']);

        Livewire::test(CreateSalesInvoice::class)
            ->fillForm(['customer_id' => $customer->id, 'account_id' => $customer->account_id, 'post_now' => true])
            ->set('data.lines', [
                'a' => ['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 20, 'tax_percent' => 15, 'item_batch_id' => $item->batches()->value('id')],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $doc = SalesDocument::query()->where('type', 'sales_invoice')->latest('id')->first();
        $this->assertFalse((bool) $doc->is_posted);
        $this->assertNull($doc->journalEntry);
    }

    public function test_tool_pages_hide_changing_actions_from_view_only_users(): void
    {
        $this->actAsUserWith(['inventory.actions-for-items.view', 'inventory.invoice-items-cost.view', 'general.settings.view']);

        $this->assertTrue(ActionsForItems::canAccess());
        $this->assertFalse(ActionsForItems::userCan(Permission::Edit));
        $this->assertFalse(InvoiceItemsCost::userCan(Permission::Post));
        $this->assertFalse(SystemSettings::userCan(Permission::Edit));

        Livewire::test(ActionsForItems::class)->assertActionHidden('updatePurchasePrice');
        Livewire::test(InvoiceItemsCost::class)->assertActionHidden('postEntries');
    }

    public function test_tool_page_permissions_appear_in_the_permission_actions(): void
    {
        $this->assertContains(Permission::Edit, SystemSettings::getPermissionActions());
        $this->assertContains(Permission::Post, InvoiceItemsCost::getPermissionActions());
    }

    public function test_export_rows_neutralise_formulas(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", SafeSpreadsheetRow::sanitize('=HYPERLINK("x")'));
        $this->assertSame("'@SUM(A1)", SafeSpreadsheetRow::sanitize('@SUM(A1)'));
        $this->assertSame('-15.5', SafeSpreadsheetRow::sanitize('-15.5'));
        $this->assertSame('Panadol', SafeSpreadsheetRow::sanitize('Panadol'));
        $this->assertSame(12, SafeSpreadsheetRow::sanitize(12));

        $row = SafeSpreadsheetRow::fromValues(['=1+1']);
        $this->assertInstanceOf(StringCell::class, $row->getCells()[0]);
    }
}
