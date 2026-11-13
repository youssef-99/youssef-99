<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Pos\DoctorResource;
use App\Filament\Resources\Pos\DoctorResource\Pages\ManageDoctors;
use App\Filament\Resources\Pos\DrugDoseResource;
use App\Filament\Resources\Pos\DrugDoseResource\Pages\ManageDrugDoses;
use App\Filament\Resources\Pos\MotivationResource;
use App\Filament\Resources\Pos\MotivationResource\Pages\ManageMotivations;
use App\Filament\Resources\Pos\PointsCustomerResource;
use App\Filament\Resources\Pos\PointsCustomerResource\Pages\ViewPointsCustomer;
use App\Filament\Resources\Pos\PointsCustomerResource\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Pos\PromotionResource;
use App\Filament\Resources\Pos\PromotionResource\Pages\ManagePromotions;
use App\Models\Doctor;
use App\Models\DrugDose;
use App\Models\PointsCustomer;
use App\Models\Promotion;
use Livewire\Livewire;
use Tests\ErpTestCase;

class PosMasterDataTest extends ErpTestCase
{
    use PosTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
    }

    public function test_master_pages_render(): void
    {
        foreach ([DoctorResource::class, DrugDoseResource::class, PromotionResource::class, MotivationResource::class, PointsCustomerResource::class] as $resource) {
            $this->get($resource::getUrl('index'))->assertOk();
        }

        $customer = PointsCustomer::query()->firstOrFail();
        $this->get(PointsCustomerResource::getUrl('view', ['record' => $customer]))
            ->assertOk()
            ->assertSee($customer->delivery_code);

        Livewire::test(TransactionsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewPointsCustomer::class])
            ->assertCanSeeTableRecords($customer->transactions()->get());
    }

    public function test_doctor_and_drug_dose_are_added_from_the_modal(): void
    {
        Livewire::test(ManageDoctors::class)
            ->callAction('create', ['code' => '77', 'name_ar' => 'د. خالد', 'specialty' => 'أطفال'])
            ->assertHasNoActionErrors();
        $this->assertTrue(Doctor::query()->where('code', '77')->exists());

        Livewire::test(ManageDrugDoses::class)
            ->callAction('create', ['code' => '77', 'name_ar' => 'قرص يومياً', 'frequency' => '1x', 'item_id' => $this->item('1001')->id])
            ->assertHasNoActionErrors();
        $this->assertSame($this->item('1001')->id, DrugDose::query()->where('code', '77')->value('item_id'));
    }

    public function test_promotion_requires_item_or_category(): void
    {
        Livewire::test(ManagePromotions::class)
            ->callAction('create', ['code' => '77', 'name_ar' => 'عرض', 'promotion_type' => 'percent', 'value' => 10, 'from_date' => now()->toDateString(), 'to_date' => now()->addDay()->toDateString()])
            ->assertHasActionErrors(['item_id', 'category_id']);

        Livewire::test(ManagePromotions::class)
            ->callAction('create', ['code' => '77', 'name_ar' => 'عرض', 'promotion_type' => 'percent', 'value' => 10, 'item_id' => $this->item('1005')->id, 'from_date' => now()->toDateString(), 'to_date' => now()->addDay()->toDateString()])
            ->assertHasNoActionErrors();
        $this->assertTrue(Promotion::query()->where('code', '77')->exists());
    }

    public function test_earned_incentives_action_runs(): void
    {
        Livewire::test(ManageMotivations::class)
            ->callAction('earned', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()])
            ->assertHasNoActionErrors()
            ->assertNotified();
    }
}
