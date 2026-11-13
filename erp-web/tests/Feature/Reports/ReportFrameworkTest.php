<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\Reports\ReportPage;
use App\Filament\Pages\Reports\ReportsHub;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Group;
use App\Models\Salesman;
use App\Models\Supplier;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Support\PermissionRegistry;
use Livewire\Livewire;
use Tests\ErpTestCase;

/**
 * Report framework: registry, hub, filter page, viewer, permissions and exports.
 */
class ReportFrameworkTest extends ErpTestCase
{
    public function test_registry_has_every_group_and_permission_page(): void
    {
        $this->assertCount(13, ReportRegistry::GROUPS);
        $this->assertSame(array_keys(ReportRegistry::GROUPS), array_keys(ReportRegistry::CATALOG));
        $this->assertCount(16, ReportRegistry::CATALOG['sales']);

        $pages = ReportRegistry::permissionPages();
        $this->assertArrayHasKey('reports.accounts.account-statement', $pages);
        $this->assertArrayHasKey('reports.inventory.items-balances', $pages);
        $this->assertContains('reports.accounts.account-statement.view', PermissionRegistry::all());

        foreach (ReportRegistry::reports() as $key => $class) {
            $this->assertTrue(ReportRegistry::exists($key), "{$class} has key {$key} that is not in the catalog");
        }
    }

    public function test_hub_lists_groups_and_links_to_reports(): void
    {
        $this->get(ReportsHub::getUrl())
            ->assertOk()
            ->assertSee(ReportRegistry::groupLabel('accounts'))
            ->assertSee(ReportRegistry::groupLabel('financing-contracts'))
            ->assertSee(ReportPage::urlFor('accounts/account-statement'), false);

        $this->get(ReportsHub::getUrl(['group' => 'customers']))
            ->assertOk()
            ->assertSee(ReportRegistry::title('customers/customer-statement'))
            ->assertDontSee(ReportPage::urlFor('accounts/account-statement'), false);
    }

    public function test_filter_page_renders_with_its_filters(): void
    {
        $this->get('/admin/reports/accounts/account-statement')
            ->assertOk()
            ->assertSee(ReportRegistry::title('accounts/account-statement'))
            ->assertSee(__('rpt.Show Report'))
            ->assertSee(__('rpt.Back'));

        foreach (array_keys(ReportRegistry::reports()) as $key) {
            $this->get(ReportPage::urlFor($key))->assertOk()->assertSee(ReportRegistry::title($key));
        }

        $this->get('/admin/reports/accounts/no-such-report')->assertNotFound();
        $this->get('/admin/reports/no-such-group/items-balances')->assertNotFound();
    }

    public function test_show_report_validates_required_filters(): void
    {
        Livewire::test(ReportPage::class, ['group' => 'accounts', 'report' => 'account-statement'])
            ->assertSet('data.from', fn (?string $from) => str_starts_with((string) $from, today()->startOfYear()->toDateString()))
            ->call('showReport')
            ->assertHasFormErrors(['account_id' => 'required']);

        Livewire::test(ReportPage::class, ['group' => 'accounts', 'report' => 'account-statement'])
            ->set('data.account_id', Account::query()->where('number', '110101')->value('id'))
            ->call('showReport')
            ->assertHasNoFormErrors();
    }

    public function test_every_implemented_report_renders_in_the_viewer_with_demo_data(): void
    {
        $this->seedDemo();
        $required = [
            'account_id' => Account::query()->where('number', '1')->value('id'),
            'customer_id' => Customer::query()->value('id'),
            'customer_group_id' => CustomerGroup::query()->value('id'),
            'supplier_id' => Supplier::query()->value('id'),
            'salesman_id' => Salesman::query()->value('id'),
            'level' => 3,
            'report_type' => 'detail',
            'transaction_type' => 'all',
        ];

        foreach (ReportRegistry::reports() as $key => $class) {
            $report = app($class);
            $query = $report->defaults();
            foreach ($report->rules() as $name => $rules) {
                if (in_array('required', $rules, true) && ! isset($query[$name]) && isset($required[$name])) {
                    $query[$name] = $required[$name];
                }
            }
            if (in_array($key, ['cost-centers/cost-center-statement', 'accreditations/accreditation-statement'], true)) {
                continue; // need records the demo data does not have; covered by their own tests
            }

            $response = $this->get(route('report.viewer', ['report' => $key] + $query));
            $this->assertSame(200, $response->status(), "{$key}: ".$response->getContent());
            $response->assertSee(Company::query()->value('name_ar'));
        }
    }

    public function test_viewer_reports_missing_required_filters(): void
    {
        $this->get(route('report.viewer', ['report' => 'accounts/account-statement']))
            ->assertStatus(422)
            ->assertSee(__('rpt.From Account'));
    }

    public function test_user_without_the_report_permission_is_denied(): void
    {
        $group = Group::query()->create(['code' => '99', 'name_ar' => 'محاسب', 'permissions' => ['reports.accounts.accounts-trial-balance.view']]);
        $user = User::query()->create(['code' => '99', 'username' => 'acc', 'name' => 'Accountant', 'email' => 'acc@example.com', 'password' => 'password', 'group_id' => $group->id, 'is_active' => true]);
        $this->actingAs($user);

        $accountId = Account::query()->where('number', '110101')->value('id');
        $statement = ['report' => 'accounts/account-statement', 'account_id' => $accountId, 'from' => '2026-01-01', 'to' => '2026-12-31'];

        $this->get(route('report.viewer', $statement))->assertForbidden();
        $this->get(route('report.export', $statement + ['format' => 'xlsx']))->assertForbidden();
        $this->get('/admin/reports/accounts/account-statement')->assertForbidden();

        $this->get(route('report.viewer', ['report' => 'accounts/accounts-trial-balance', 'level' => 4, 'from' => '2026-01-01', 'to' => '2026-12-31']))->assertOk();
        $this->get(ReportsHub::getUrl())->assertOk()
            ->assertSee(ReportPage::urlFor('accounts/accounts-trial-balance'), false)
            ->assertDontSee(ReportPage::urlFor('accounts/account-statement'), false);
    }

    public function test_user_without_any_report_permission_cannot_open_the_hub(): void
    {
        $group = Group::query()->create(['code' => '98', 'name_ar' => 'بدون', 'permissions' => []]);
        $user = User::query()->create(['code' => '98', 'username' => 'none', 'name' => 'None', 'email' => 'none@example.com', 'password' => 'password', 'group_id' => $group->id, 'is_active' => true]);
        $this->actingAs($user);

        $this->get(ReportsHub::getUrl())->assertForbidden();
    }

    public function test_xlsx_and_csv_exports_download(): void
    {
        $this->seedDemo();
        $query = ['report' => 'accounts/accounts-trial-balance', 'level' => 4, 'from' => today()->startOfYear()->toDateString(), 'to' => today()->toDateString()];

        $xlsx = $this->get(route('report.export', $query + ['format' => 'xlsx']));
        $xlsx->assertOk()->assertDownload();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', file_get_contents($xlsx->baseResponse->getFile()->getPathname()));

        $csv = $this->get(route('report.export', $query + ['format' => 'csv']));
        $csv->assertOk()->assertDownload();
        $content = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('110101', $content);

        $this->get(route('report.viewer', ['report' => 'accounts/accounts-trial-balance']).'/export/pdf')->assertNotFound();
    }
}
