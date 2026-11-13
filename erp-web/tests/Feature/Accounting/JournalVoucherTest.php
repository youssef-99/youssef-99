<?php

namespace Tests\Feature\Accounting;

use App\Exceptions\ErpException;
use App\Filament\Resources\Accounting\JournalVoucherResource;
use App\Filament\Resources\Accounting\JournalVoucherResource\Pages\CreateJournalVoucher;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Services\ManualJournalService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\ErpTestCase;

class JournalVoucherTest extends ErpTestCase
{
    private function accountId(string $number): int
    {
        return (int) Account::query()->where('number', $number)->value('id');
    }

    public function test_balanced_voucher_is_created_and_posted_from_the_form(): void
    {
        Livewire::test(CreateJournalVoucher::class)
            ->fillForm(['description' => 'Rent', 'post_now' => true])
            ->set('data.lines', [
                'a' => ['account_id' => $this->accountId('520102'), 'debit' => 750, 'credit' => 0],
                'b' => ['account_id' => $this->accountId('110101'), 'debit' => 0, 'credit' => 750],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = JournalEntry::query()->where('entry_type', 'manual')->latest('id')->firstOrFail();
        $this->assertTrue($entry->is_posted);
        $this->assertEquals(750, (float) $entry->total_debit);
        $this->assertEquals(750, (float) $entry->total_credit);
        $this->assertCount(2, $entry->lines);
        $this->assertNotEmpty($entry->number);
    }

    public function test_unbalanced_voucher_is_saved_but_not_posted(): void
    {
        Livewire::test(CreateJournalVoucher::class)
            ->fillForm(['post_now' => true])
            ->set('data.lines', [
                'a' => ['account_id' => $this->accountId('520102'), 'debit' => 750, 'credit' => 0],
                'b' => ['account_id' => $this->accountId('110101'), 'debit' => 0, 'credit' => 700],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $entry = JournalEntry::query()->where('entry_type', 'manual')->latest('id')->firstOrFail();
        $this->assertFalse($entry->is_posted);

        $this->expectException(ErpException::class);
        ManualJournalService::post($entry);
    }

    public function test_parent_accounts_cannot_be_posted_to(): void
    {
        $entry = JournalEntry::query()->create(['branch_id' => 1, 'date' => now()]);
        $entry->lines()->create(['account_id' => $this->accountId('1101'), 'debit' => 10]);
        $entry->lines()->create(['account_id' => $this->accountId('110101'), 'credit' => 10]);

        $this->expectException(ErpException::class);
        ManualJournalService::post($entry);
    }

    public function test_live_totals_show_the_difference(): void
    {
        Livewire::test(CreateJournalVoucher::class)
            ->set('data.lines', [
                'a' => ['account_id' => $this->accountId('520102'), 'debit' => 0, 'credit' => 0],
            ])
            ->set('data.lines.a.debit', 120)
            ->assertSet('data.total_debit', 120.0)
            ->assertSet('data.difference', 120.0);
    }

    public function test_generated_entries_cannot_be_edited_or_unposted(): void
    {
        $entry = JournalEntry::query()->create(['branch_id' => 1, 'date' => now(), 'entry_type' => 'receipt', 'source_type' => 'voucher', 'source_id' => 1]);

        $this->assertFalse(JournalVoucherResource::canEdit($entry));
        $this->expectException(ErpException::class);
        ManualJournalService::unpost($entry->refresh());
    }

    public function test_excel_import_parses_a_generated_xlsx_file(): void
    {
        $costCenter = CostCenter::query()->create(['code' => 'CC1', 'name_ar' => 'الإدارة']);
        $path = tempnam(sys_get_temp_dir(), 'jv').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Account No.', 'Debit', 'Credit', 'Description', 'Cost Center']));
        $writer->addRow(Row::fromValues(['520102', 1200.5, null, 'Rent', 'CC1']));
        $writer->addRow(Row::fromValues([110101, null, 1200.5, 'Cash', null]));
        $writer->close();

        $lines = ManualJournalService::importLines($path);
        @unlink($path);

        $this->assertCount(2, $lines);
        $this->assertSame($this->accountId('520102'), $lines[0]['account_id']);
        $this->assertSame(1200.5, $lines[0]['debit']);
        $this->assertSame(0.0, $lines[0]['credit']);
        $this->assertSame($costCenter->id, $lines[0]['cost_center_id']);
        $this->assertSame($this->accountId('110101'), $lines[1]['account_id']);
        $this->assertSame(1200.5, $lines[1]['credit']);

        $state = JournalVoucherResource::appendLines(['empty' => ['account_id' => null, 'debit' => 0, 'credit' => 0]], $lines);
        $this->assertCount(2, $state);
    }

    public function test_excel_import_parses_csv_and_reports_unknown_accounts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'jv').'.csv';
        file_put_contents($path, "520102,100,,Rent,\n000000,,100,Unknown,\n");

        try {
            ManualJournalService::importLines($path);
            $this->fail('Expected an import error.');
        } catch (ErpException $e) {
            $this->assertStringContainsString('000000', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_import_action_appends_lines_to_the_form(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('lines.csv', "Account No.,Debit,Credit,Description,Cost Center\n520102,300,,Rent,\n110101,,300,Cash,\n");

        $component = Livewire::test(CreateJournalVoucher::class)
            ->callAction(TestAction::make('importExcel')->schemaComponent(), data: ['file' => $file])
            ->assertHasNoActionErrors();

        $lines = array_values($component->get('data.lines'));
        $this->assertCount(2, $lines);
        $this->assertSame($this->accountId('520102'), $lines[0]['account_id']);
        $this->assertEquals(300.0, $component->get('data.total_credit'));
    }
}
