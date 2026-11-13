<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Services\PartyAccountService;
use App\Services\PosService;
use App\Services\PurchaseDocumentService;
use App\Services\SalesDocumentService;
use Tests\ErpTestCase;

/**
 * Inventory, sales, salesmen and purchase reports: every report runs on the demo data,
 * correctness on hand-built documents, and the viewer for one report per group.
 */
class TradeReportsTest extends ErpTestCase
{
    /** Reports the demo data is expected to give rows for. */
    private const EXPECT_ROWS = [
        'inventory/items-balances', 'inventory/items-transaction-statement', 'inventory/items-list-by-expiry-date',
        'inventory/print-item-barcode', 'inventory/item-transaction', 'inventory/items-prices',
        'sales/daily-sales-details', 'sales/items-statistics', 'sales/sales-statistics', 'sales/top-sales',
        'sales/customer-sales-transaction', 'sales/customer-sales-statistics', 'sales/salesman-customers-sales-statistics',
        'sales/daily-pos-transactions', 'sales/points-customers', 'sales/items-sales-analysis', 'sales/user-sales-analysis',
        'sales/user-sales-transaction', 'sales/daily-quotations-details', 'sales/motivations',
        'salesmen/salesmen-sales-analysis',
        'purchase/item-purchase-details', 'purchase/daily-purchase-details',
        'purchase/daily-purchase-orders-details',
    ];

    public function test_every_trade_report_runs_on_demo_data_in_every_mode(): void
    {
        $this->seedDemo();
        $from = today()->subMonths(2)->toDateString();
        $to = today()->toDateString();

        foreach (ReportRegistry::reports() as $key => $class) {
            if (! in_array(ReportRegistry::find($key)::group(), ['inventory', 'sales', 'salesmen', 'purchase'], true) || $key === 'salesmen/salesman-statement' || $key === 'salesmen/salesmen-sales-collection') {
                continue;
            }
            $report = app($class);
            $filters = array_merge($report->defaults(), str_starts_with($key, 'inventory/items-list') ? ['status' => 'all'] : ['from' => $from, 'to' => $to]);

            $result = $report->run($filters);
            $this->assertInstanceOf(ReportResult::class, $result);
            $this->assertNotEmpty($result->columns, $key);
            if (in_array($key, self::EXPECT_ROWS, true)) {
                $this->assertNotEmpty($result->rows, "{$key} has no rows on the demo data");
            }

            foreach ($report->filters() as $field) {
                if ($field->getName() === 'report_type' || in_array($field->getName(), ['show_by', 'status', 'kind'], true)) {
                    foreach (array_keys($field->getOptions()) as $option) {
                        $report->run([$field->getName() => $option] + $filters);
                    }
                }
            }
        }
    }

    public function test_items_balances_opening_movement_and_closing(): void
    {
        [$item] = $this->stockAndSales();
        $report = ReportRegistry::make('inventory/items-balances');

        $result = $report->run($report->defaults() + ['item_id' => $item->id, 'filter_by' => 'balance']);
        $row = $this->rowWhere($result, 'code', 'T1');
        $this->assertEquals(0, $row['opening']);
        $this->assertEquals(11, $row['qty_in']);
        $this->assertEquals(4, $row['qty_out']);
        $this->assertEquals(7, $row['closing']);

        $later = $report->run(['from' => today()->toDateString(), 'item_id' => $item->id] + $report->defaults());
        $this->assertEquals(6, $this->rowWhere($later, 'code', 'T1')['opening']);

        $byBatch = $report->run(['show_by' => 'batch', 'qty_more' => 6, 'qty_less' => 10] + $report->defaults());
        $this->assertSame(['T1'], array_column(array_column($byBatch->rows, 'cells'), 'code'));
    }

    public function test_items_transaction_statement_running_balance(): void
    {
        [$item] = $this->stockAndSales();
        $report = ReportRegistry::make('inventory/items-transaction-statement');
        $result = $report->run(['item_id' => $item->id, 'from' => today()->subDays(30)->toDateString(), 'to' => today()->toDateString()]);

        $balances = array_values(array_filter(array_map(fn ($r) => $r['type'] === 'row' ? $r['cells']['balance'] : null, $result->rows), fn ($v) => $v !== null));
        $this->assertSame([10.0, 6.0, 7.0], $balances);
    }

    public function test_items_sales_analysis_profit_and_top_sales_ordering(): void
    {
        $this->stockAndSales();
        $filters = ['from' => today()->subDays(30)->toDateString(), 'to' => today()->toDateString()];

        $analysis = ReportRegistry::make('sales/items-sales-analysis')->run($filters);
        $row = $this->rowWhere($analysis, 'code', 'T1');
        $this->assertEquals(3, $row['quantity']);
        $this->assertEquals(30, $row['sales']);
        $this->assertEquals(15, $row['cost']);
        $this->assertEquals(15, $row['profit']);
        $this->assertEquals(50, $row['margin']);

        $byQty = ReportRegistry::make('sales/top-sales')->run(['report_type' => 'quantity', 'limit' => 5] + $filters);
        $this->assertSame(['T2', 'T1'], array_column(array_column($byQty->rows, 'cells'), 'code'));
        $byValue = ReportRegistry::make('sales/top-sales')->run(['report_type' => 'value', 'limit' => 1] + $filters);
        $this->assertSame(['T1'], array_column(array_column($byValue->rows, 'cells'), 'code'));

        $stats = ReportRegistry::make('sales/sales-statistics')->run(['report_type' => 'month'] + $filters);
        $this->assertEquals(round(30 + 5 * 2, 2), $stats->footer[0]['cells']['net']);
    }

    public function test_daily_purchase_details_invoice_totals(): void
    {
        $this->stockAndSales();
        $result = ReportRegistry::make('purchase/daily-purchase-details')->run([
            'report_type' => 'detail', 'from' => today()->subDays(30)->toDateString(), 'to' => today()->toDateString(),
        ]);

        $this->assertSame(__('rpt.Purchases – Invoice Totals'), $result->subtitle);
        $this->assertCount(1, $result->rows);
        $row = $result->rows[0]['cells'];
        $this->assertSame('300000000000099', $row['supplier_vat']);
        $this->assertEquals(90, $row['gross']);
        $this->assertEquals(4, $row['discount']);
        $this->assertEquals(12.9, $row['vat']);
        $this->assertEquals(98.9, $row['total']);
        $this->assertEquals(98.9, $result->footer[0]['cells']['total']);
    }

    public function test_daily_foreign_purchase_details_converts_to_riyals(): void
    {
        $this->seedDemo();
        $invoice = PurchaseDocument::query()->where('type', 'foreign_purchase_invoice')->firstOrFail();
        $invoice->update(['warehouse_id' => Warehouse::query()->value('id')]);
        PurchaseDocumentService::post($invoice->fresh());

        $result = ReportRegistry::make('purchase/daily-foreign-purchase-details')->run([
            'report_type' => 'detail', 'from' => today()->subDays(30)->toDateString(), 'to' => today()->toDateString(),
        ]);
        $row = $result->rows[0]['cells'];
        $this->assertEquals(3.75, $row['exchange_rate']);
        $this->assertEquals(round((float) $invoice->fresh()->grand_total, 2), $row['foreign_total']);
        $this->assertEquals(round((float) $invoice->fresh()->grand_total * 3.75, 2), $row['total']);
    }

    public function test_viewer_renders_one_report_per_group(): void
    {
        $this->seedDemo();
        foreach ([
            'inventory/items-balances' => [],
            'sales/top-sales' => [],
            'salesmen/salesmen-sales-analysis' => [],
            'purchase/daily-purchase-details' => [],
        ] as $key => $query) {
            $this->get(route('report.viewer', ['report' => $key] + $query))
                ->assertOk()
                ->assertSee(ReportRegistry::title($key));
        }

        $this->get(route('report.viewer', ['report' => 'inventory/print-item-barcode']))
            ->assertOk()
            ->assertSee('JsBarcode', false)
            ->assertSee('6281001000011');
    }

    public function test_exports_write_formula_like_text_as_plain_text(): void
    {
        $category = ItemCategory::query()->create(['code' => 'X', 'name_ar' => 'تصدير']);
        Item::query()->create(['code' => 'X1', 'name_ar' => '=HYPERLINK("http://evil.example","x")', 'category_id' => $category->id, 'sale_price' => 1]);
        $query = ['report' => 'inventory/items-prices', 'category_id' => $category->id];

        $xlsx = $this->get(route('report.export', $query + ['format' => 'xlsx']));
        $xlsx->assertOk();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($xlsx->baseResponse->getFile()->getPathname()));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $strings = (string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString("'=HYPERLINK", html_entity_decode($sheet.$strings, ENT_QUOTES | ENT_XML1));

        $csv = $this->get(route('report.export', $query + ['format' => 'csv']));
        $csv->assertOk();
        $content = (string) file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringNotContainsString(',=HYPERLINK', $content);
        $this->assertStringNotContainsString('"=HYPERLINK', $content);
    }

    /**
     * Item T1 (cost 5, VAT 15 %): purchased 10, sold 4 on POS at 10, 1 returned.
     * Item T2: purchased 20 at 2 less 10 % (cost 1.8), sold 5 at 2.
     *
     * @return array{0: Item, 1: Item}
     */
    protected function stockAndSales(): array
    {
        $branch = Branch::query()->firstOrFail();
        $warehouse = Warehouse::query()->where('branch_id', $branch->id)->firstOrFail();
        $category = ItemCategory::query()->create(['code' => 'T', 'name_ar' => 'اختبار']);
        $t1 = Item::query()->create(['code' => 'T1', 'name_ar' => 'صنف 1', 'category_id' => $category->id, 'vat_rate' => 15, 'purchase_price' => 5, 'sale_price' => 10]);
        $t2 = Item::query()->create(['code' => 'T2', 'name_ar' => 'صنف 2', 'category_id' => $category->id, 'vat_rate' => 15, 'purchase_price' => 2, 'sale_price' => 5]);

        $supplier = Supplier::query()->create(['code' => 'S9', 'name_ar' => 'مورد', 'vat_no' => '300000000000099']);
        PartyAccountService::sync($supplier);
        $purchase = PurchaseDocument::query()->create([
            'type' => 'purchase_invoice', 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'date' => today()->subDays(2),
            'supplier_id' => $supplier->id, 'account_id' => $supplier->fresh()->account_id,
        ]);
        $purchase->lines()->create(['item_id' => $t1->id, 'quantity' => 10, 'unit_price' => 5, 'tax_percent' => 15]);
        $purchase->lines()->create(['item_id' => $t2->id, 'quantity' => 20, 'unit_price' => 2, 'tax_percent' => 15, 'discount_percent' => 10]);
        PurchaseDocumentService::post($purchase);

        $sale = SalesDocument::query()->create([
            'type' => 'pos_invoice', 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'date' => today()->subDay(), 'payment_method' => 'cash',
        ]);
        $sale->lines()->create(['item_id' => $t1->id, 'quantity' => 4, 'unit_price' => 10, 'tax_percent' => 15]);
        $sale->lines()->create(['item_id' => $t2->id, 'quantity' => 5, 'unit_price' => 2, 'tax_percent' => 15]);
        SalesDocumentService::recalculate($sale);
        $sale->update(['cash_amount' => $sale->grand_total, 'amount_received' => $sale->grand_total]);
        SalesDocumentService::post($sale);

        $return = SalesDocument::query()->create([
            'type' => 'pos_return', 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'date' => today(), 'original_document_id' => $sale->id,
        ]);
        $return->lines()->create(['item_id' => $t1->id, 'quantity' => 1, 'unit_price' => 10, 'tax_percent' => 15]);
        PosService::post($return);

        return [$t1, $t2];
    }

    /** @return array<string, mixed> */
    protected function rowWhere(ReportResult $result, string $column, string $value): array
    {
        foreach ($result->rows as $row) {
            if ($row['type'] === 'row' && (string) ($row['cells'][$column] ?? '') === $value) {
                return $row['cells'];
            }
        }
        $this->fail("No row with {$column} = {$value}");
    }
}
