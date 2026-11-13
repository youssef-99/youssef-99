<?php

namespace Tests\Feature\Inventory;

use App\Filament\Pages\Inventory\InvoiceItemsCost;
use App\Models\Item;
use App\Models\SalesDocument;
use App\Models\StockMovement;
use App\Services\InvoiceCostService;
use App\Services\SalesDocumentService;
use App\Services\Settings;
use Livewire\Livewire;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\Concerns\CreatesInventory;

class InvoiceItemsCostTest extends ErpTestCase
{
    use CreatesInventory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.zatca.enabled' => false]);
    }

    protected function posInvoice(Item $item, float $qty, string $date): SalesDocument
    {
        $invoice = SalesDocument::query()->create([
            'type' => 'pos_invoice', 'branch_id' => $this->branch()->id, 'warehouse_id' => $this->mainWarehouse()->id,
            'date' => $date, 'payment_method' => 'cash',
        ]);
        $invoice->lines()->create(['item_id' => $item->id, 'quantity' => $qty, 'unit_price' => 30, 'tax_percent' => 15]);
        SalesDocumentService::recalculate($invoice);
        $invoice->update(['cash_amount' => $invoice->grand_total]);

        return SalesDocumentService::post($invoice);
    }

    public function test_update_cost_recosts_lines_from_the_moving_average_ledger_and_post_entries_rebuilds_cogs(): void
    {
        $item = $this->makeItem('C1');
        $this->receive($item, 10, 10, date: now()->subDays(5)->toDateString());
        $invoice = $this->posInvoice($item, 4, now()->subDays(3)->toDateString());
        $this->assertEquals(10, (float) $invoice->lines->sole()->unit_cost);

        // A purchase entered late but dated before the sale changes the sale's moving-average cost.
        $this->receive($item, 10, 20, date: now()->subDays(4)->toDateString());

        Livewire::test(InvoiceItemsCost::class)
            ->assertCanSeeTableRecords($invoice->lines)
            ->callAction('updateCost', data: ['from' => now()->subDays(10)->toDateString(), 'to' => now()->toDateString()])
            ->assertHasNoActionErrors();

        $invoice->refresh();
        $this->assertEquals(15, (float) $invoice->lines->sole()->unit_cost);
        $this->assertEquals(60.00, (float) $invoice->cost_total);
        $this->assertEquals(15, (float) StockMovement::query()->where('source_type', 'sales_document')->where('source_id', $invoice->id)->value('unit_cost'));
        $this->assertEquals(15, (float) $item->refresh()->average_cost);

        Livewire::test(InvoiceItemsCost::class)
            ->callAction('postEntries', data: ['from' => now()->subDays(10)->toDateString(), 'to' => now()->toDateString()])
            ->assertHasNoActionErrors();

        $entry = $invoice->journalEntry()->with('lines')->first();
        $this->assertEquals(60.00, (float) $entry->lines->firstWhere('account_id', Settings::account('cogs'))->debit);
        $this->assertEquals(60.00, (float) $entry->lines->firstWhere('account_id', Settings::account('inventory'))->credit);
        $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);
        $this->assertEquals((float) $invoice->grand_total, (float) $entry->lines->firstWhere('account_id', Settings::account('cash'))->debit);
    }

    public function test_update_cost_ignores_invoices_outside_the_period(): void
    {
        $item = $this->makeItem('C2');
        $this->receive($item, 10, 10, date: now()->subDays(30)->toDateString());
        $invoice = $this->posInvoice($item, 2, now()->subDays(20)->toDateString());
        $this->receive($item, 10, 30, date: now()->subDays(25)->toDateString());

        $result = InvoiceCostService::updateCosts(now()->subDays(5), now());

        $this->assertSame(0, $result['documents']);
        $this->assertEquals(10, (float) $invoice->lines()->value('unit_cost'));
    }
}
