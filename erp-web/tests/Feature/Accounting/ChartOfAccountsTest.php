<?php

namespace Tests\Feature\Accounting;

use App\Exceptions\ErpException;
use App\Filament\Resources\Accounting\AccountResource\Pages\ManageAccounts;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\AccountService;
use App\Services\Settings;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\ErpTestCase;

class ChartOfAccountsTest extends ErpTestCase
{
    public function test_account_is_created_under_a_parent_from_the_modal(): void
    {
        $parent = Account::query()->where('number', '5201')->firstOrFail();

        Livewire::test(ManageAccounts::class)
            ->callAction('create', data: [
                'parent_id' => $parent->id,
                'number' => '520199',
                'level' => 4,
                'name_ar' => 'مصروفات صيانة',
                'name_en' => 'Maintenance',
                'nature' => 'debit',
                'type' => 'expense',
            ])
            ->assertHasNoActionErrors();

        $account = Account::query()->where('number', '520199')->firstOrFail();
        $this->assertSame($parent->id, $account->parent_id);
        $this->assertSame(4, (int) $account->level);
        $this->assertTrue($account->is_postable);
    }

    public function test_account_number_and_name_are_required_and_unique(): void
    {
        Livewire::test(ManageAccounts::class)
            ->callAction('create', data: ['number' => '110101', 'name_ar' => '', 'level' => 1, 'nature' => 'debit', 'type' => 'general'])
            ->assertHasActionErrors(['number' => 'unique', 'name_ar' => 'required']);
    }

    public function test_account_without_children_or_transactions_can_be_deleted(): void
    {
        $account = Account::query()->create(['number' => '999', 'level' => 1, 'name_ar' => 'حساب مؤقت', 'nature' => 'debit']);

        Livewire::test(ManageAccounts::class)->callTableAction('delete', $account);

        $this->assertModelMissing($account);
    }

    public function test_parent_account_cannot_be_deleted(): void
    {
        $parent = Account::query()->where('number', '1101')->firstOrFail();

        Livewire::test(ManageAccounts::class)->callTableAction('delete', $parent)->assertNotified();

        $this->assertModelExists($parent);
    }

    public function test_account_with_journal_lines_cannot_be_deleted(): void
    {
        $account = Account::query()->create(['number' => '520198', 'parent_id' => Account::query()->where('number', '5201')->value('id'), 'name_ar' => 'مصروف', 'nature' => 'debit']);
        $entry = JournalEntry::query()->create(['branch_id' => $this->admin->default_branch_id ?? 1, 'date' => now()]);
        $entry->lines()->create(['account_id' => $account->id, 'debit' => 10]);

        $this->expectException(ErpException::class);
        AccountService::delete($account);
    }

    public function test_default_posting_account_cannot_be_deleted(): void
    {
        $cash = Account::query()->findOrFail(Settings::account('cash'));

        $this->expectException(ErpException::class);
        $cash->delete();
    }

    public function test_chart_exports_to_xlsx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'coa').'.xlsx';
        AccountService::writeChartXlsx($path);

        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        $this->assertCount(Account::query()->count() + 1, $rows);
        $this->assertSame('1', (string) $rows[1][0]);
        $this->assertSame('الأصول', $rows[1][1]);
    }

    public function test_chart_page_renders_with_print_link(): void
    {
        $this->get(route('filament.admin.resources.accounting.accounts.index'))
            ->assertOk()
            ->assertSee('110101');
    }
}
