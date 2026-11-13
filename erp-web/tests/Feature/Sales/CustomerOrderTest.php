<?php

namespace Tests\Feature\Sales;

use App\Exceptions\ErpException;
use App\Filament\Resources\Sales\CustomerOrderResource\Pages\ListCustomerOrders;
use App\Models\Customer;
use App\Models\Item;
use App\Models\SalesDocument;
use App\Services\CustomerOrderService;
use App\Services\SalesDocumentService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\ErpTestCase;

class CustomerOrderTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    protected function postedOrder(): SalesDocument
    {
        $customer = Customer::query()->firstOrFail();
        $order = SalesDocument::query()->create([
            'type' => 'customer_order', 'branch_id' => 1, 'date' => now(), 'customer_id' => $customer->id, 'account_id' => $customer->account_id,
        ]);
        foreach (['1002' => 10, '1005' => 4] as $code => $qty) {
            $item = Item::query()->where('code', $code)->firstOrFail();
            $order->lines()->create(['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => $qty, 'unit_price' => $item->sale_price, 'tax_percent' => 15]);
        }
        SalesDocumentService::recalculate($order);

        return SalesDocumentService::post($order);
    }

    public function test_an_order_must_be_posted_before_approval(): void
    {
        $customer = Customer::query()->firstOrFail();
        $draft = SalesDocument::query()->create(['type' => 'customer_order', 'branch_id' => 1, 'date' => now(), 'customer_id' => $customer->id]);

        $this->expectException(ErpException::class);
        CustomerOrderService::approve($draft);
    }

    public function test_full_approval_through_the_list_action(): void
    {
        $order = $this->postedOrder();
        $this->assertSame('confirmed', $order->status);

        Livewire::test(ListCustomerOrders::class)
            ->callAction(TestAction::make('approveOrder')->table($order))
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertSame($this->admin->id, $order->approved_by);
        $this->assertEquals([10, 4], $order->lines->map(fn ($l) => (float) $l->approved_qty)->all());
    }

    public function test_partial_approval_and_conversion_uses_the_approved_quantities(): void
    {
        $order = $this->postedOrder();
        [$first, $second] = $order->lines->all();

        CustomerOrderService::approve($order, [$first->id => 6, $second->id => 0]);
        $this->assertSame('partially_approved', $order->refresh()->status);

        $this->expectExceptionOnInvalidApproval($order, [$first->id => 11]);

        $invoice = SalesDocumentService::convertToInvoice($order->refresh());
        $this->assertSame($order->id, $invoice->source_document_id);
        $this->assertCount(1, $invoice->lines);
        $this->assertEquals(6, (float) $invoice->lines->first()->quantity);
        $this->assertFalse(CustomerOrderService::canDecide($order->refresh()));
    }

    public function test_reject_with_reason(): void
    {
        $order = $this->postedOrder();

        Livewire::test(ListCustomerOrders::class)
            ->callAction(TestAction::make('rejectOrder')->table($order), data: ['reason' => ''])
            ->assertHasFormErrors(['reason' => 'required']);

        Livewire::test(ListCustomerOrders::class)
            ->callAction(TestAction::make('rejectOrder')->table($order), data: ['reason' => 'Credit limit exceeded'])
            ->assertHasNoFormErrors();

        $order->refresh();
        $this->assertSame('rejected', $order->status);
        $this->assertSame('Credit limit exceeded', $order->rejection_reason);

        $this->expectException(ErpException::class);
        SalesDocumentService::convertToInvoice($order);
    }

    public function test_status_tabs_filter_orders(): void
    {
        $approved = $this->postedOrder();
        CustomerOrderService::approve($approved);
        $rejected = $this->postedOrder();
        CustomerOrderService::reject($rejected, 'No stock');
        $pending = $this->postedOrder();

        Livewire::test(ListCustomerOrders::class)
            ->set('activeTab', 'rejected')
            ->assertCanSeeTableRecords([$rejected])
            ->assertCanNotSeeTableRecords([$approved, $pending])
            ->set('activeTab', 'posted')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$approved, $rejected]);
    }

    /** @param  array<int, float>  $quantities */
    protected function expectExceptionOnInvalidApproval(SalesDocument $order, array $quantities): void
    {
        try {
            CustomerOrderService::approve($order->refresh(), $quantities);
            $this->fail('Approving more than the ordered quantity must fail.');
        } catch (ErpException) {
            $this->assertSame('partially_approved', $order->refresh()->status);
        }
    }
}
