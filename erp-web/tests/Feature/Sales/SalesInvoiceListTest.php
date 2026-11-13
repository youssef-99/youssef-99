<?php

namespace Tests\Feature\Sales;

use App\Filament\Resources\Sales\SalesInvoiceResource\Pages\ListSalesInvoices;
use App\Models\Item;
use App\Models\SalesDocument;
use Livewire\Livewire;
use Tests\ErpTestCase;

class SalesInvoiceListTest extends ErpTestCase
{
    public function test_confirmed_tabs_and_post_invoices_to_accounting(): void
    {
        $this->seedDemo();
        $posted = SalesDocument::query()->where('type', 'sales_invoice')->where('is_posted', true)->firstOrFail();
        $item = Item::query()->where('code', '1006')->firstOrFail();
        $draft = SalesDocument::query()->create([
            'type' => 'sales_invoice', 'branch_id' => $posted->branch_id, 'date' => now(),
            'customer_id' => $posted->customer_id, 'account_id' => $posted->account_id,
        ]);
        $draft->lines()->create(['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 2, 'unit_price' => 9, 'tax_percent' => 15]);

        Livewire::test(ListSalesInvoices::class)
            ->set('activeTab', 'unconfirmed')
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$posted])
            ->set('activeTab', 'confirmed')
            ->assertCanSeeTableRecords([$posted])
            ->assertCanNotSeeTableRecords([$draft])
            ->callAction('postAll');

        $this->assertTrue($draft->refresh()->is_posted);
        $this->assertNotNull($draft->journal_entry_id);
    }
}
