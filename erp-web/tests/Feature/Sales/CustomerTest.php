<?php

namespace Tests\Feature\Sales;

use App\Exceptions\ErpException;
use App\Filament\Resources\Sales\CustomerResource;
use App\Filament\Resources\Sales\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\Sales\CustomerResource\Pages\ListCustomers;
use App\Models\Account;
use App\Models\Activity;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\District;
use App\Models\JournalEntry;
use App\Models\MarketingRep;
use App\Models\Region;
use App\Models\Salesman;
use App\Services\CustomerService;
use App\Services\Settings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\ErpTestCase;

class CustomerTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    /** @return array<string, mixed> */
    protected function validFormData(array $overrides = []): array
    {
        $city = City::query()->whereHas('districts')->firstOrFail();

        return array_merge([
            'code' => '9001',
            'name_ar' => 'صيدلية النور',
            'name_en' => 'Al Noor Pharmacy',
            'customer_type' => 'company',
            'customer_group_id' => CustomerGroup::query()->value('id'),
            'activity_id' => Activity::query()->value('id'),
            'marketing_rep_id' => MarketingRep::query()->value('id'),
            'salesman_id' => Salesman::query()->value('id'),
            'country_id' => $city->country_id,
            'city_id' => $city->id,
            'district_id' => District::query()->where('city_id', $city->id)->value('id'),
            'region_id' => Region::query()->value('id'),
            'vat_no' => '310000000000003',
            'opening_debit' => 1500,
            'opening_credit' => 0,
            'credit_limit' => 20000,
            'payment_term_days' => 45,
            'account_opening_date' => now()->startOfYear()->toDateString(),
        ], $overrides);
    }

    public function test_creating_a_customer_with_an_opening_balance_creates_its_account_and_a_balanced_opening_entry(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm($this->validFormData())
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::query()->where('code', '9001')->firstOrFail();
        $account = $customer->account;

        $this->assertNotNull($account);
        $this->assertSame(Settings::account('receivables'), $account->parent_id);
        $this->assertSame('customer', $account->type);
        $this->assertSame('صيدلية النور', $account->name_ar);

        $entry = JournalEntry::query()->where('source_type', $customer->getMorphClass())->where('source_id', $customer->id)->with('lines')->firstOrFail();
        $this->assertEquals(1500, (float) $entry->total_debit);
        $this->assertEquals($entry->total_debit, $entry->total_credit);
        $this->assertEquals(1500, (float) $entry->lines->firstWhere('account_id', $account->id)->debit);
        $this->assertEquals(1500, (float) $entry->lines->firstWhere('account_id', Settings::account('opening_balances'))->credit);
    }

    public function test_required_fields_are_validated(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'code' => '', 'name_ar' => '', 'customer_type' => null, 'country_id' => null,
                'opening_debit' => null, 'opening_credit' => null, 'credit_limit' => null, 'payment_term_days' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'code' => 'required', 'name_ar' => 'required', 'customer_type' => 'required', 'customer_group_id' => 'required',
                'activity_id' => 'required', 'marketing_rep_id' => 'required', 'salesman_id' => 'required', 'country_id' => 'required',
                'city_id' => 'required', 'district_id' => 'required', 'region_id' => 'required', 'opening_debit' => 'required',
                'opening_credit' => 'required', 'credit_limit' => 'required', 'payment_term_days' => 'required',
            ]);

        Livewire::test(CreateCustomer::class)
            ->fillForm($this->validFormData(['vat_no' => '123']))
            ->call('create')
            ->assertHasFormErrors(['vat_no']);

        $this->assertFalse(Customer::query()->where('code', '9001')->exists());
    }

    public function test_customer_with_documents_cannot_be_deleted_and_an_unused_customer_is_deleted_with_its_account(): void
    {
        $used = Customer::query()->whereHas('salesDocuments')->firstOrFail();

        try {
            CustomerService::delete($used);
            $this->fail('Deleting a customer with documents must be blocked.');
        } catch (ErpException) {
            $this->assertModelExists($used);
        }

        Livewire::test(CreateCustomer::class)->fillForm($this->validFormData())->call('create')->assertHasNoFormErrors();
        $customer = Customer::query()->where('code', '9001')->firstOrFail();
        $accountId = $customer->account_id;

        Livewire::test(ListCustomers::class)
            ->callAction(TestAction::make('deleteCustomer')->table($customer))
            ->assertHasNoErrors();

        $this->assertModelMissing($customer);
        $this->assertNull(Account::query()->find($accountId));
        $this->assertFalse(JournalEntry::query()->where('source_type', $customer->getMorphClass())->where('source_id', $customer->id)->exists());
    }

    public function test_export_writes_the_customers_to_xlsx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cust').'.xlsx';
        CustomerService::exportXlsx(CustomerResource::getEloquentQuery(), $path);

        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        $this->assertCount(Customer::query()->count() + 1, $rows);
        $this->assertSame(Customer::query()->orderBy('id')->value('code'), (string) $rows[1][0]);
    }

    public function test_list_and_view_pages_render(): void
    {
        $customer = Customer::query()->firstOrFail();

        $this->get(CustomerResource::getUrl('index'))->assertOk()->assertSee($customer->name_ar);
        $this->get(CustomerResource::getUrl('view', ['record' => $customer]))->assertOk()->assertSee($customer->account->number);
    }
}
