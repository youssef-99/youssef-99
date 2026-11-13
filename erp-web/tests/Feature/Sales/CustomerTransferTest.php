<?php

namespace Tests\Feature\Sales;

use App\Exceptions\ErpException;
use App\Filament\Resources\Sales\CustomerResource\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\CustomerTransfer;
use App\Models\Salesman;
use App\Services\CustomerTransferService;
use Livewire\Livewire;
use Tests\ErpTestCase;

class CustomerTransferTest extends ErpTestCase
{
    public function test_transfer_moves_selected_customers_and_records_the_transfer(): void
    {
        $from = Salesman::query()->create(['code' => '71', 'name_ar' => 'مندوب أ']);
        $to = Salesman::query()->create(['code' => '72', 'name_ar' => 'مندوب ب']);
        $first = Customer::query()->create(['code' => '701', 'name_ar' => 'عميل 1', 'salesman_id' => $from->id]);
        $second = Customer::query()->create(['code' => '702', 'name_ar' => 'عميل 2', 'salesman_id' => $from->id]);

        Livewire::test(ListCustomers::class)
            ->callAction('transferCustomers', data: [
                'from_salesman_id' => $from->id,
                'to_salesman_id' => $to->id,
                'customer_ids' => [$first->id],
            ])
            ->assertHasNoFormErrors();

        $this->assertSame($to->id, $first->refresh()->salesman_id);
        $this->assertSame($from->id, $second->refresh()->salesman_id);

        $transfer = CustomerTransfer::query()->latest('id')->firstOrFail();
        $this->assertSame($from->id, $transfer->from_salesman_id);
        $this->assertSame($to->id, $transfer->to_salesman_id);
        $this->assertSame([$first->id], $transfer->customer_ids);
        $this->assertSame($this->admin->id, $transfer->created_by);
    }

    public function test_transfer_without_selection_moves_all_customers_of_the_salesman(): void
    {
        $from = Salesman::query()->create(['code' => '71', 'name_ar' => 'مندوب أ']);
        $to = Salesman::query()->create(['code' => '72', 'name_ar' => 'مندوب ب']);
        Customer::query()->create(['code' => '701', 'name_ar' => 'عميل 1', 'salesman_id' => $from->id]);
        Customer::query()->create(['code' => '702', 'name_ar' => 'عميل 2', 'salesman_id' => $from->id]);

        $transfer = CustomerTransferService::transfer($from->id, $to->id);

        $this->assertCount(2, $transfer->customer_ids);
        $this->assertSame(0, Customer::query()->where('salesman_id', $from->id)->count());
    }

    public function test_transfer_rejects_same_salesman_and_foreign_customers(): void
    {
        $from = Salesman::query()->create(['code' => '71', 'name_ar' => 'مندوب أ']);
        $to = Salesman::query()->create(['code' => '72', 'name_ar' => 'مندوب ب']);
        $other = Customer::query()->create(['code' => '703', 'name_ar' => 'عميل 3', 'salesman_id' => $to->id]);

        Livewire::test(ListCustomers::class)
            ->callAction('transferCustomers', data: ['from_salesman_id' => $from->id, 'to_salesman_id' => $from->id])
            ->assertHasFormErrors(['to_salesman_id']);

        $this->expectException(ErpException::class);
        CustomerTransferService::transfer($from->id, $to->id, [$other->id]);
    }
}
