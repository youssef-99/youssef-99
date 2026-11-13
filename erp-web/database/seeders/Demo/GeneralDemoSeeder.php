<?php

namespace Database\Seeders\Demo;

use App\Models\Activity;
use App\Models\Branch;
use App\Models\EgsUnit;
use App\Models\Group;
use App\Models\Stakeholder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * General module demo data: a second warehouse, activities, a stakeholder,
 * an EGS unit, a cashiers group and a cashier user. Idempotent.
 */
class GeneralDemoSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->orderBy('id')->firstOrFail();

        Warehouse::query()->firstOrCreate(['code' => '2'], ['branch_id' => $branch->id, 'name_ar' => 'مستودع الصيدلية', 'name_en' => 'Pharmacy Store']);

        foreach ([['1', 'صيدليات', 'Pharmacies'], ['2', 'مستشفيات', 'Hospitals'], ['3', 'مستوصفات', 'Clinics']] as [$code, $ar, $en]) {
            Activity::query()->firstOrCreate(['code' => $code], ['name_ar' => $ar, 'name_en' => $en]);
        }

        Stakeholder::query()->firstOrCreate(['code' => '1'], ['name_ar' => 'الشريك المؤسس', 'name_en' => 'Founding Partner', 'share_percent' => 100]);

        EgsUnit::query()->firstOrCreate(['branch_id' => $branch->id, 'unit_name' => 'POS-01'], [
            'serial_number' => '1-ERPWeb|2-1.0|3-'.$branch->code.'-POS-01',
            'invoicing_type' => '1100',
            'industry_sector' => 'Pharmacy',
            'location' => $branch->address ?? 'Riyadh',
            'environment' => 'sandbox',
        ]);

        $cashiers = Group::query()->firstOrCreate(['code' => '2'], [
            'name_ar' => 'الكاشير',
            'name_en' => 'Cashiers',
            'permissions' => [
                'dashboard.dashboard.view',
                'sales.pos-invoices.view',
                'sales.pos-invoices.create',
                'sales.pos-returns.view',
                'sales.pos-returns.create',
                'general.stores.view',
            ],
        ]);

        User::query()->firstOrCreate(['username' => 'cashier'], [
            'code' => '2',
            'name' => 'كاشير الفرع الرئيسي',
            'email' => 'cashier@example.com',
            'password' => 'password',
            'group_id' => $cashiers->id,
            'default_branch_id' => $branch->id,
        ]);
    }
}
