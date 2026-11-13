<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\ErpException;
use App\Models\Agent;
use App\Models\Item;
use App\Models\Unit;
use App\Services\ItemImportService;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\Concerns\CreatesInventory;

class ItemImportTest extends ErpTestCase
{
    use CreatesInventory;

    protected function tempFile(string $extension): string
    {
        return tempnam(sys_get_temp_dir(), 'items').'.'.$extension;
    }

    /** @return array<string, mixed> */
    protected function defaults(array $overrides = []): array
    {
        return $overrides + [
            'category_id' => $this->category()->id,
            'item_type' => 'batch_expiry',
            'expiry_date' => null,
            'agent_id' => null,
            'tax_exemption_reason' => 'VATEX-SA-35',
        ];
    }

    public function test_csv_import_creates_and_updates_items_with_the_bulk_fields(): void
    {
        $existing = $this->makeItem('2001', ['sale_price' => 5]);
        $agent = Agent::query()->create(['code' => 'AG', 'name_ar' => 'وكيل']);
        $unit = Unit::query()->firstOrFail();
        $path = $this->tempFile('csv');
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", [
            'code,barcode,name_ar,name_en,unit,sale price,purchase price,vat rate',
            "2001,6280000000001,صنف معدل,Updated,{$unit->code},12.5,8,15",
            '2002,6280000000002,أموكسيسيلين,Amoxicillin,,21,14,0',
            ',,,No Arabic name,,1,1,15',
        ]));

        $result = ItemImportService::import($path, $this->defaults(['agent_id' => $agent->id, 'expiry_date' => '2027-06-01']));

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertCount(1, $result['errors']);

        $existing->refresh();
        $this->assertSame('صنف معدل', $existing->name_ar);
        $this->assertEquals(12.5, (float) $existing->sale_price);
        $this->assertSame($unit->id, $existing->unit_id);
        $this->assertSame('batch_expiry', $existing->item_type);
        $this->assertNull($existing->tax_exemption_reason);

        $new = Item::query()->where('code', '2002')->firstOrFail();
        $this->assertEquals(0, (float) $new->vat_rate);
        $this->assertSame('VATEX-SA-35', $new->tax_exemption_reason);
        $this->assertSame($agent->id, $new->agent_id);
        $this->assertSame($this->category()->id, $new->category_id);
        $this->assertSame('2027-06-01', $new->batches()->sole()->expiry_date->toDateString());
    }

    public function test_xlsx_import_without_header_assigns_codes(): void
    {
        $path = $this->tempFile('xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['', '6280000000009', 'كريم', 'Cream', '', 29, 18, 15]));
        $writer->close();

        $result = ItemImportService::import($path, $this->defaults(['item_type' => 'standard']));

        $this->assertSame(1, $result['created']);
        $item = Item::query()->where('barcode', '6280000000009')->firstOrFail();
        $this->assertNotEmpty($item->code);
        $this->assertEquals(29, (float) $item->sale_price);
        $this->assertSame(0, $item->batches()->count());
    }

    public function test_zero_vat_rows_need_an_exemption_reason(): void
    {
        $path = $this->tempFile('csv');
        file_put_contents($path, "3001,,صنف,,,10,5,0\n");

        $result = ItemImportService::import($path, $this->defaults(['tax_exemption_reason' => null]));

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['errors']);
    }

    public function test_unsupported_files_are_rejected(): void
    {
        $path = $this->tempFile('pdf');
        file_put_contents($path, 'x');

        $this->expectException(ErpException::class);
        ItemImportService::import($path, $this->defaults());
    }
}
