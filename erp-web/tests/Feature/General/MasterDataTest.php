<?php

namespace Tests\Feature\General;

use App\Filament\Resources\General\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\General\CurrencyResource\Pages\ManageCurrencies;
use App\Filament\Resources\General\DistrictResource\Pages\ManageDistricts;
use App\Filament\Resources\General\EgsUnitResource\Pages\ManageEgsUnits;
use App\Filament\Resources\General\FiscalYearResource\Pages\ManageFiscalYears;
use App\Filament\Resources\General\WarehouseResource\Pages\ManageWarehouses;
use App\Models\Branch;
use App\Models\City;
use App\Models\Company;
use App\Models\Currency;
use App\Models\District;
use App\Models\EgsUnit;
use App\Models\Warehouse;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\ErpTestCase;

class MasterDataTest extends ErpTestCase
{
    public function test_warehouse_requires_a_branch_and_is_filtered_by_branch(): void
    {
        Livewire::test(ManageWarehouses::class)
            ->callAction('create', ['name_ar' => 'مستودع', 'branch_id' => null])
            ->assertHasFormErrors(['branch_id' => 'required']);

        $company = Company::query()->firstOrFail();
        $second = Branch::query()->create(['company_id' => $company->id, 'code' => '2', 'name_ar' => 'فرع جدة']);

        Livewire::test(ManageWarehouses::class)
            ->callAction('create', ['name_ar' => 'مستودع جدة', 'branch_id' => $second->id])
            ->assertHasNoFormErrors();

        $jeddah = Warehouse::query()->where('name_ar', 'مستودع جدة')->firstOrFail();
        $this->assertSame('2', $jeddah->code);

        Livewire::test(ManageWarehouses::class)
            ->filterTable('branch_id', $second->id)
            ->assertCanSeeTableRecords([$jeddah])
            ->assertCanNotSeeTableRecords(Warehouse::query()->where('branch_id', '!=', $second->id)->get());
    }

    public function test_company_validates_the_vat_number(): void
    {
        Livewire::test(CreateCompany::class)
            ->fillForm(['name_ar' => 'شركة', 'vat_no' => '123'])
            ->call('create')
            ->assertHasFormErrors(['vat_no' => 'regex']);

        Livewire::test(CreateCompany::class)
            ->fillForm(['name_ar' => 'شركة', 'vat_no' => '310000000000003', 'commercial_register' => '4030000000'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('companies', ['code' => '2', 'vat_no' => '310000000000003']);
    }

    public function test_only_one_currency_is_the_default(): void
    {
        Livewire::test(ManageCurrencies::class)
            ->callAction('create', ['name_ar' => 'درهم إماراتي', 'iso_code' => 'aed', 'exchange_rate' => 1.02, 'is_default' => true])
            ->assertHasNoFormErrors();

        $this->assertSame(['AED'], Currency::query()->where('is_default', true)->pluck('iso_code')->all());
    }

    public function test_fiscal_year_must_end_after_it_starts(): void
    {
        Livewire::test(ManageFiscalYears::class)
            ->callAction('create', ['year' => 2035, 'start_date' => '2035-12-31', 'end_date' => '2035-01-01'])
            ->assertHasFormErrors(['end_date']);

        Livewire::test(ManageFiscalYears::class)
            ->callAction('create', ['year' => now()->year, 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString()])
            ->assertHasFormErrors(['year' => 'unique']);
    }

    public function test_districts_are_filtered_by_country_and_city(): void
    {
        $riyadh = City::query()->where('name_en', 'Riyadh')->firstOrFail();

        Livewire::test(ManageDistricts::class)
            ->filterTable('location', ['country_id' => $riyadh->country_id, 'city_id' => $riyadh->id])
            ->assertCanSeeTableRecords($riyadh->districts)
            ->assertCanNotSeeTableRecords(District::query()->where('city_id', '!=', $riyadh->id)->get());
    }

    public function test_egs_unit_keeps_its_secret_when_left_blank_on_edit(): void
    {
        $unit = EgsUnit::query()->create([
            'branch_id' => Branch::query()->value('id'),
            'unit_name' => 'POS-01',
            'compliance_csid' => 'csid',
            'compliance_secret' => 'top-secret',
        ]);

        Livewire::test(ManageEgsUnits::class)
            ->callAction(TestAction::make('edit')->table($unit), ['location' => 'Riyadh', 'invoicing_type' => '0100'])
            ->assertHasNoFormErrors();

        $unit->refresh();
        $this->assertSame('top-secret', $unit->compliance_secret);
        $this->assertSame('0100', $unit->invoicing_type);
        $this->assertSame('Riyadh', $unit->location);
    }
}
