<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Branch;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\District;
use App\Models\FiscalYear;
use App\Models\Group;
use App\Models\Region;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Settings;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Minimum data for a working system: company, branch, warehouse, fiscal year,
 * geography, currencies, units, chart of accounts, default posting accounts and an admin user.
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->firstOrCreate(['code' => '1'], [
            'name_ar' => 'صيدلية النموذج الطبية',
            'name_en' => 'Demo Medical Pharmacy',
            'commercial_register' => '1010000000',
            'vat_no' => '300000000000003',
            'phone' => '0110000000',
            'city_name' => 'الرياض',
            'district_name' => 'العليا',
            'street_name' => 'طريق الملك فهد',
            'building_no' => '1234',
            'postal_code' => '12211',
        ]);

        $branch = Branch::query()->firstOrCreate(['code' => '1'], ['company_id' => $company->id, 'name_ar' => 'الفرع الرئيسي', 'name_en' => 'Main Branch']);
        Warehouse::query()->firstOrCreate(['code' => '1'], ['branch_id' => $branch->id, 'name_ar' => 'المستودع الرئيسي', 'name_en' => 'Main Warehouse']);

        foreach ([now()->year - 1, now()->year, now()->year + 1] as $y) {
            FiscalYear::query()->firstOrCreate(['year' => $y], ['start_date' => "{$y}-01-01", 'end_date' => "{$y}-12-31"]);
        }

        $sa = Country::query()->firstOrCreate(['code' => '1'], ['name_ar' => 'المملكة العربية السعودية', 'name_en' => 'Saudi Arabia', 'iso_code' => 'SA']);
        foreach ([['1', 'الرياض', 'Riyadh', ['العليا', 'الملز', 'النسيم']], ['2', 'جدة', 'Jeddah', ['الروضة', 'الصفا']], ['3', 'الدمام', 'Dammam', ['الفيصلية']]] as [$code, $ar, $en, $districts]) {
            $city = City::query()->firstOrCreate(['code' => $code], ['country_id' => $sa->id, 'name_ar' => $ar, 'name_en' => $en]);
            foreach ($districts as $i => $d) {
                District::query()->firstOrCreate(['code' => $code.($i + 1)], ['city_id' => $city->id, 'name_ar' => $d]);
            }
        }
        foreach ([['1', 'المنطقة الوسطى', 'Central'], ['2', 'المنطقة الغربية', 'Western'], ['3', 'المنطقة الشرقية', 'Eastern']] as [$c, $ar, $en]) {
            Region::query()->firstOrCreate(['code' => $c], ['country_id' => $sa->id, 'name_ar' => $ar, 'name_en' => $en]);
        }

        Currency::query()->firstOrCreate(['code' => '1'], ['name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'iso_code' => 'SAR', 'exchange_rate' => 1, 'is_default' => true]);
        Currency::query()->firstOrCreate(['code' => '2'], ['name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar', 'iso_code' => 'USD', 'exchange_rate' => 3.75]);
        Currency::query()->firstOrCreate(['code' => '3'], ['name_ar' => 'يورو', 'name_en' => 'Euro', 'iso_code' => 'EUR', 'exchange_rate' => 4.05]);

        foreach ([['1', 'علبة', 'Box'], ['2', 'شريط', 'Strip'], ['3', 'حبة', 'Piece'], ['4', 'زجاجة', 'Bottle'], ['5', 'أنبوب', 'Tube']] as [$c, $ar, $en]) {
            Unit::query()->firstOrCreate(['code' => $c], ['name_ar' => $ar, 'name_en' => $en]);
        }

        $this->seedChartOfAccounts();

        Group::query()->firstOrCreate(['code' => '1'], ['name_ar' => 'مدير النظام', 'name_en' => 'Administrators', 'permissions' => []]);

        // A closure so the password is only required when the admin user is actually created.
        User::query()->firstOrCreate(['username' => 'admin'], fn (): array => [
            'code' => '1',
            'name' => 'مدير النظام',
            'email' => 'admin@example.com',
            'password' => config('erp.initial_admin_password')
                ?: (app()->isProduction() ? throw new RuntimeException('Set ERP_ADMIN_PASSWORD to create the admin user in production.') : 'password'),
            'is_admin' => true,
            'group_id' => Group::query()->value('id'),
            'default_branch_id' => $branch->id,
        ]);
    }

    protected function seedChartOfAccounts(): void
    {
        // [number, name_ar, name_en, nature, balance sheet item, type, settings key]
        $tree = [
            ['1', 'الأصول', 'Assets', 'debit', 'assets', 'general', null, [
                ['11', 'الأصول المتداولة', 'Current Assets', null, null, 'general', null, [
                    ['1101', 'النقدية والبنوك', 'Cash and Banks', null, null, 'general', null, [
                        ['110101', 'الصندوق الرئيسي', 'Main Cash Box', null, null, 'cash', 'cash'],
                        ['110102', 'حساب مدى / فيزا', 'Mada / Visa Clearing', null, null, 'bank', 'card'],
                        ['110103', 'البنك - مصرف الراجحي', 'Bank - Al Rajhi', null, null, 'bank', null],
                    ]],
                    ['1102', 'العملاء', 'Customers', null, null, 'customer', 'receivables', []],
                    ['1103', 'المخزون', 'Inventory', null, null, 'general', null, [
                        ['110301', 'مخزون البضاعة', 'Merchandise Inventory', null, null, 'inventory', 'inventory'],
                        ['110302', 'بضاعة بالطريق', 'Stock in Transit', null, null, 'inventory', 'stock_in_transit'],
                    ]],
                    ['1104', 'أوراق القبض', 'Notes Receivable', null, null, 'general', null, [
                        ['110401', 'شيكات تحت التحصيل', 'Cheques Under Collection', null, null, 'general', 'cheques_under_collection'],
                    ]],
                    ['1105', 'ضريبة القيمة المضافة', 'VAT', null, null, 'general', null, [
                        ['110501', 'ضريبة المدخلات', 'Input VAT', null, null, 'vat', 'vat_input'],
                    ]],
                ]],
                ['12', 'الأصول الثابتة', 'Fixed Assets', null, null, 'general', null, [
                    ['1201', 'الأثاث والتجهيزات', 'Furniture and Fixtures', null, null, 'general', null, [
                        ['120101', 'أثاث', 'Furniture', null, null, 'general', null],
                        ['120102', 'أجهزة حاسب', 'Computers', null, null, 'general', null],
                    ]],
                ]],
            ]],
            ['2', 'الخصوم', 'Liabilities', 'credit', 'liabilities', 'general', null, [
                ['21', 'الخصوم المتداولة', 'Current Liabilities', null, null, 'general', null, [
                    ['2101', 'الموردون', 'Suppliers', null, null, 'supplier', 'payables', []],
                    ['2102', 'ضريبة المخرجات', 'Output VAT', null, null, 'general', null, [
                        ['210201', 'ضريبة القيمة المضافة المستحقة', 'Output VAT Payable', null, null, 'vat', 'vat_output'],
                    ]],
                    ['2103', 'الاعتمادات المستندية', 'Letters of Credit', null, null, 'general', null, [
                        ['210301', 'اعتمادات مستندية', 'Letters of Credit', null, null, 'lc', null],
                    ]],
                    ['2104', 'تمويل المرابحة', 'Murabaha Financing', null, null, 'general', null, [
                        ['210401', 'قروض المرابحة', 'Murabaha Loans', null, null, 'general', null],
                    ]],
                ]],
            ]],
            ['3', 'حقوق الملكية', 'Equity', 'credit', 'equity', 'general', null, [
                ['31', 'رأس المال', 'Capital', null, null, 'general', null, [
                    ['3101', 'رأس المال', 'Capital', null, null, 'general', null, [
                        ['310101', 'رأس المال المدفوع', 'Paid-in Capital', null, null, 'equity', null],
                        ['310102', 'الأرصدة الافتتاحية', 'Opening Balances', null, null, 'equity', 'opening_balances'],
                    ]],
                ]],
            ]],
            ['4', 'الإيرادات', 'Revenue', 'credit', 'revenue', 'general', null, [
                ['41', 'المبيعات', 'Sales', null, null, 'general', null, [
                    ['4101', 'إيرادات المبيعات', 'Sales Revenue', null, null, 'general', null, [
                        ['410101', 'المبيعات', 'Sales', null, null, 'sales', 'sales'],
                        ['410102', 'مردودات المبيعات', 'Sales Returns', 'debit', null, 'sales', 'sales_returns'],
                        ['410103', 'خصم مسموح به', 'Sales Discount', 'debit', null, 'sales', 'sales_discount'],
                    ]],
                ]],
                ['42', 'إيرادات أخرى', 'Other Revenue', null, null, 'general', null, [
                    ['4201', 'إيرادات متنوعة', 'Miscellaneous Revenue', null, null, 'general', null, [
                        ['420101', 'خصم مكتسب', 'Purchase Discount Earned', null, null, 'revenue', 'purchase_discount'],
                    ]],
                ]],
            ]],
            ['5', 'المصروفات', 'Expenses', 'debit', 'expenses', 'general', null, [
                ['51', 'تكلفة المبيعات', 'Cost of Sales', null, null, 'general', null, [
                    ['5101', 'تكلفة البضاعة المباعة', 'Cost of Goods Sold', null, null, 'general', null, [
                        ['510101', 'تكلفة البضاعة المباعة', 'Cost of Goods Sold', null, null, 'expense', 'cogs'],
                        ['510102', 'فروقات الجرد والتسويات', 'Inventory Differences', null, null, 'expense', 'inventory_adjustment'],
                    ]],
                ]],
                ['52', 'المصروفات التشغيلية', 'Operating Expenses', null, null, 'general', null, [
                    ['5201', 'مصروفات عمومية وإدارية', 'General and Administrative', null, null, 'general', null, [
                        ['520101', 'الرواتب والأجور', 'Salaries and Wages', null, null, 'expense', null],
                        ['520102', 'الإيجارات', 'Rent', null, null, 'expense', null],
                        ['520103', 'عمولات بنكية', 'Bank Charges', null, null, 'expense', null],
                        ['520104', 'مصاريف مشتريات خارجية', 'Foreign Purchase Expenses', null, null, 'expense', 'foreign_purchase_expenses'],
                        ['520105', 'أرباح المرابحة', 'Murabaha Profit Expense', null, null, 'expense', null],
                    ]],
                ]],
            ]],
        ];

        $insert = function (array $nodes, ?Account $parent, string $nature, string $bsItem) use (&$insert) {
            foreach ($nodes as $node) {
                [$number, $ar, $en, $n, $bs, $type, $key] = $node;
                $children = $node[7] ?? null;
                $account = Account::query()->firstOrCreate(['number' => $number], [
                    'parent_id' => $parent?->id,
                    'level' => $parent ? $parent->level + 1 : 1,
                    'name_ar' => $ar,
                    'name_en' => $en,
                    'nature' => $n ?? $nature,
                    'balance_sheet_item' => $bs ?? $bsItem,
                    'type' => $type,
                    'is_postable' => $children === null,
                ]);
                if ($children !== null) {
                    $account->forceFill(['is_postable' => false])->saveQuietly();
                }
                if ($key) {
                    Settings::set("accounts.{$key}", $account->id);
                }
                if ($children) {
                    $insert($children, $account, $n ?? $nature, $bs ?? $bsItem);
                }
            }
        };

        foreach ($tree as $root) {
            $insert([$root], null, $root[3], $root[4]);
        }
    }
}
