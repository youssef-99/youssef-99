<?php

namespace Tests\Feature\Dashboard;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\BranchSalesChart;
use App\Filament\Widgets\Data\DashboardMetrics;
use App\Filament\Widgets\SalesStatsOverview;
use App\Filament\Widgets\TopItemsChart;
use App\Models\Branch;
use App\Models\Group;
use App\Models\Item;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesDocumentService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\ErpTestCase;

class DashboardTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    /** Last 7 days, as the dashboard defaults to. */
    protected function lastWeek(bool $withTax = true): DashboardMetrics
    {
        return DashboardMetrics::fromFilters(['tax' => $withTax ? 'with' : 'without']);
    }

    /** Posted sales, POS and cash sales invoices in the period, minus returns. */
    protected function expectedTotal(string $column): float
    {
        $sum = fn (array $types): float => (float) SalesDocument::query()->where('is_posted', true)->whereIn('type', $types)
            ->whereDate('date', '>=', now()->subDays(6)->toDateString())->sum($column);

        return round($sum(DashboardMetrics::INVOICE_TYPES) - $sum(DashboardMetrics::RETURN_TYPES), 2);
    }

    public function test_dashboard_renders_at_its_slug_and_is_the_panel_home(): void
    {
        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('لوحة التحكم')
            ->assertSeeLivewire(SalesStatsOverview::class);

        $this->get('/admin')->assertRedirect(Dashboard::getUrl());
        $this->assertSame('dashboard.dashboard', Dashboard::getPermissionKey());
    }

    public function test_dashboard_requires_the_dashboard_permission(): void
    {
        $group = Group::query()->create(['code' => 'D1', 'name_ar' => 'بدون لوحة', 'permissions' => ['general.branches.view']]);
        $user = User::factory()->create(['group_id' => $group->id]);

        $this->flushSession();
        $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();

        $group->update(['permissions' => ['dashboard.dashboard.view']]);
        $this->actingAs($user->fresh())->get('/admin/dashboard')->assertOk();
    }

    public function test_filters_default_to_the_last_seven_days_with_tax(): void
    {
        $component = Livewire::test(Dashboard::class);

        $this->assertSame(now()->subDays(6)->toDateString(), $component->get('filters.from'));
        $this->assertSame(now()->toDateString(), $component->get('filters.to'));
        $this->assertSame('with', $component->get('filters.tax'));
    }

    public function test_summary_with_and_without_tax(): void
    {
        $withTax = $this->lastWeek(true)->summary();
        $withoutTax = $this->lastWeek(false)->summary();

        $this->assertSame(SalesDocument::query()->where('is_posted', true)->whereIn('type', DashboardMetrics::INVOICE_TYPES)
            ->whereDate('date', '>=', now()->subDays(6)->toDateString())->count(), $withTax['invoices_count']);
        $this->assertEqualsWithDelta($this->expectedTotal('grand_total'), $withTax['sales'], 0.001);
        $this->assertEqualsWithDelta($this->expectedTotal('net_total'), $withoutTax['sales'], 0.001);
        $this->assertEqualsWithDelta($this->expectedTotal('tax_total'), $withTax['vat'], 0.001);
        $this->assertEqualsWithDelta($withTax['sales'] - $withoutTax['sales'], $withTax['vat'], 0.01);
    }

    public function test_returns_are_subtracted_and_unposted_documents_ignored(): void
    {
        $before = $this->lastWeek()->summary();
        $item = Item::query()->where('code', '1001')->firstOrFail();
        $quantityBefore = $this->lastWeek()->topItems()->firstWhere('item_id', $item->id)['quantity'];
        $original = SalesDocument::query()->where('type', 'pos_invoice')->where('is_posted', true)->firstOrFail();

        $return = SalesDocument::query()->create([
            'type' => 'pos_return', 'branch_id' => $original->branch_id, 'warehouse_id' => $original->warehouse_id, 'date' => now(),
            'original_document_id' => $original->id, 'payment_method' => 'cash',
        ]);
        $return->lines()->create(['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 1, 'unit_price' => 12, 'tax_percent' => 15, 'item_batch_id' => $item->batches()->value('id')]);
        SalesDocumentService::post($return->fresh());

        $draft = SalesDocument::query()->create(['type' => 'pos_invoice', 'branch_id' => $original->branch_id, 'warehouse_id' => $original->warehouse_id, 'date' => now()]);
        $draft->lines()->create(['item_id' => $item->id, 'quantity' => 50, 'unit_price' => 12, 'tax_percent' => 15]);
        SalesDocumentService::recalculate($draft);

        $after = $this->lastWeek()->summary();
        $return->refresh();

        $this->assertSame($before['returns_count'] + 1, $after['returns_count']);
        $this->assertSame($before['invoices_count'], $after['invoices_count']);
        $this->assertEqualsWithDelta($before['sales'] - (float) $return->grand_total, $after['sales'], 0.001);

        $panadol = $this->lastWeek()->topItems()->firstWhere('item_id', $item->id);
        $this->assertEquals($quantityBefore - 1, $panadol['quantity']);
    }

    public function test_top_items_are_ordered_by_net_quantity(): void
    {
        $expected = [];
        $lines = SalesDocumentLine::query()->with('salesDocument')->whereHas('salesDocument', fn ($q) => $q->where('is_posted', true)
            ->whereIn('type', [...DashboardMetrics::INVOICE_TYPES, ...DashboardMetrics::RETURN_TYPES])
            ->whereDate('date', '>=', now()->subDays(6)->toDateString()))->get();

        foreach ($lines as $line) {
            $sign = in_array($line->salesDocument->type, DashboardMetrics::RETURN_TYPES, true) ? -1 : 1;
            $expected[$line->item_id]['quantity'] = ($expected[$line->item_id]['quantity'] ?? 0) + $sign * (float) $line->quantity;
            $expected[$line->item_id]['with'] = ($expected[$line->item_id]['with'] ?? 0) + $sign * (float) $line->net;
            $expected[$line->item_id]['without'] = ($expected[$line->item_id]['without'] ?? 0) + $sign * ((float) $line->net - (float) $line->tax);
        }

        $items = $this->lastWeek()->topItems();
        $withoutTax = $this->lastWeek(false)->topItems()->keyBy('item_id');

        $this->assertNotEmpty($items);
        $this->assertLessThanOrEqual(10, $items->count());
        $this->assertSame($items->pluck('quantity')->sortDesc()->values()->all(), $items->pluck('quantity')->all());
        $this->assertEquals(max(array_column($expected, 'quantity')), $items->first()['quantity']);

        foreach ($items as $item) {
            $this->assertEqualsWithDelta($expected[$item['item_id']]['quantity'], $item['quantity'], 0.001);
            $this->assertEqualsWithDelta($expected[$item['item_id']]['with'], $item['amount'], 0.01);
            $this->assertEqualsWithDelta($expected[$item['item_id']]['without'], $withoutTax[$item['item_id']]['amount'], 0.01);
        }
    }

    public function test_period_filter_limits_the_documents(): void
    {
        $today = DashboardMetrics::fromFilters(['from' => now()->toDateString(), 'to' => now()->toDateString()])->summary();

        $this->assertSame(SalesDocument::query()->where('is_posted', true)->whereIn('type', DashboardMetrics::INVOICE_TYPES)->whereDate('date', now()->toDateString())->count(), $today['invoices_count']);
        $this->assertGreaterThan(0, $today['invoices_count']);

        $empty = new DashboardMetrics(CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2020-01-31'));
        $this->assertSame(0.0, $empty->summary()['sales']);
        $this->assertCount(0, $empty->topItems());
    }

    public function test_sales_by_branch_per_day(): void
    {
        $second = Branch::query()->create(['company_id' => Branch::query()->value('company_id'), 'code' => '99', 'name_ar' => 'فرع جدة', 'name_en' => 'Jeddah Branch']);
        $warehouse = Warehouse::query()->create(['branch_id' => $second->id, 'code' => '99', 'name_ar' => 'مستودع جدة']);
        $item = Item::query()->where('code', '1006')->firstOrFail();
        $invoice = SalesDocument::query()->create(['type' => 'cash_sales_invoice', 'branch_id' => $second->id, 'warehouse_id' => $warehouse->id, 'date' => now(), 'payment_method' => 'cash']);
        $invoice->lines()->create(['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 100, 'tax_percent' => 15]);
        SalesDocumentService::recalculate($invoice);
        $invoice->forceFill(['is_posted' => true])->saveQuietly();

        $data = $this->lastWeek(false)->salesByBranch();

        $this->assertCount(7, $data['labels']);
        $this->assertSame(now()->toDateString(), end($data['labels']));
        $this->assertGreaterThanOrEqual(2, count($data['branches']));

        $jeddah = collect($data['branches'])->firstWhere('branch_id', $second->id);
        $this->assertEquals(100.0, $jeddah['total']);
        $this->assertEquals(100.0, end($jeddah['values']));

        $mainId = Branch::query()->orderBy('id')->value('id');
        $main = collect($data['branches'])->firstWhere('branch_id', $mainId);
        $expectedMain = SalesDocument::query()->where('is_posted', true)->where('branch_id', $mainId)->whereDate('date', '>=', now()->subDays(6)->toDateString())
            ->get()->sum(fn (SalesDocument $doc): float => (in_array($doc->type, DashboardMetrics::RETURN_TYPES, true) ? -1 : (in_array($doc->type, DashboardMetrics::INVOICE_TYPES, true) ? 1 : 0)) * (float) $doc->net_total);
        $this->assertEqualsWithDelta($expectedMain, $main['total'], 0.01);
        $this->assertEqualsWithDelta($this->expectedTotal('net_total'), collect($data['branches'])->sum('total'), 0.01);
        $this->assertEqualsWithDelta($main['total'], array_sum($main['values']), 0.01);

        $months = DashboardMetrics::fromFilters(['from' => now()->subMonths(5)->toDateString()])->salesByBranch();
        $this->assertSame(now()->format('Y-m'), end($months['labels']));
    }

    public function test_widgets_render_with_page_filters(): void
    {
        $filters = ['from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString(), 'tax' => 'without'];

        Livewire::test(SalesStatsOverview::class, ['pageFilters' => $filters])
            ->assertOk()
            ->assertSee(number_format($this->expectedTotal('net_total'), 2));

        Livewire::test(TopItemsChart::class, ['pageFilters' => $filters])->assertOk();
        Livewire::test(BranchSalesChart::class, ['pageFilters' => $filters])->assertOk();
    }
}
