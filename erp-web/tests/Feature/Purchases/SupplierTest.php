<?php

namespace Tests\Feature\Purchases;

use App\Filament\Resources\Purchases\SupplierGroupResource\Pages\ManageSupplierGroups;
use App\Filament\Resources\Purchases\SupplierResource\Pages\CreateSupplier;
use App\Filament\Resources\Purchases\SupplierResource\Pages\EditSupplier;
use App\Models\Account;
use App\Models\City;
use App\Models\Currency;
use App\Models\District;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\SupplierGroup;
use App\Services\Settings;
use App\Services\SupplierService;
use Livewire\Livewire;
use Tests\ErpTestCase;

class SupplierTest extends ErpTestCase
{
    public function test_supplier_group_is_added_from_the_modal(): void
    {
        Livewire::test(ManageSupplierGroups::class)
            ->callAction('create', data: ['code' => '7', 'name_ar' => 'موردو التجميل', 'name_en' => 'Cosmetics Suppliers'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('supplier_groups', ['code' => '7', 'name_en' => 'Cosmetics Suppliers']);
    }

    public function test_creating_a_supplier_creates_its_account_and_opening_balance_entry(): void
    {
        $district = District::query()->firstOrFail();
        $city = City::query()->findOrFail($district->city_id);
        $group = SupplierGroup::query()->create(['code' => '1', 'name_ar' => 'موردو الأدوية']);

        Livewire::test(CreateSupplier::class)
            ->fillForm([
                'code' => '50',
                'name_ar' => 'شركة الدواء',
                'supplier_type' => 'local',
                'supplier_group_id' => $group->id,
                'currency_id' => Currency::query()->where('is_default', true)->value('id'),
                'country_id' => $city->country_id,
                'city_id' => $city->id,
                'district_id' => $district->id,
                'vat_no' => '300000000000003',
                'opening_debit' => 0,
                'opening_credit' => 5000,
                'credit_limit' => 100000,
                'payment_term_days' => 45,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $supplier = Supplier::query()->where('code', '50')->firstOrFail();
        $account = Account::query()->findOrFail($supplier->account_id);
        $this->assertSame(Settings::account('payables'), $account->parent_id);
        $this->assertSame('supplier', $account->type);
        $this->assertSame('شركة الدواء', $account->name_ar);

        $entry = JournalEntry::query()->where('source_type', 'supplier')->where('source_id', $supplier->id)->firstOrFail();
        $this->assertSame('opening', $entry->entry_type);
        $this->assertEquals(5000, (float) $entry->lines()->where('account_id', $account->id)->value('credit'));
        $this->assertEquals(5000, (float) $entry->lines()->where('account_id', Settings::account('opening_balances'))->value('debit'));
    }

    public function test_changing_the_opening_balance_rebuilds_the_entry(): void
    {
        $district = District::query()->firstOrFail();
        $city = City::query()->findOrFail($district->city_id);
        $supplier = Supplier::query()->create([
            'code' => '60', 'name_ar' => 'مورد', 'supplier_group_id' => SupplierGroup::query()->create(['code' => '1', 'name_ar' => 'م'])->id,
            'currency_id' => Currency::query()->value('id'), 'country_id' => $city->country_id, 'city_id' => $city->id, 'district_id' => $district->id,
        ]);

        Livewire::test(EditSupplier::class, ['record' => $supplier->getRouteKey()])
            ->fillForm(['opening_credit' => 1200, 'account_id' => null])
            ->call('save')
            ->assertHasFormErrors(['account_id' => 'required']);

        SupplierService::afterSave($supplier);
        Livewire::test(EditSupplier::class, ['record' => $supplier->getRouteKey()])
            ->fillForm(['opening_credit' => 1200])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(1200, (float) JournalEntry::query()->where('source_type', 'supplier')->where('source_id', $supplier->id)->value('total_debit'));
    }

    public function test_required_fields_and_single_sided_opening_balance(): void
    {
        Livewire::test(CreateSupplier::class)
            ->fillForm(['name_ar' => null, 'supplier_group_id' => null, 'country_id' => null, 'opening_debit' => 10, 'opening_credit' => 10])
            ->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'supplier_group_id' => 'required', 'country_id' => 'required', 'city_id' => 'required', 'district_id' => 'required', 'opening_credit']);

        $this->assertSame(0, Supplier::query()->count());
    }
}
