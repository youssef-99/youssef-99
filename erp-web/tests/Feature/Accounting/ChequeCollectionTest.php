<?php

namespace Tests\Feature\Accounting;

use App\Exceptions\ErpException;
use App\Filament\Resources\Accounting\ChequeCollectionResource\Pages\CreateChequeCollection;
use App\Models\Account;
use App\Models\ChequeCollection;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Services\ChequeCollectionService;
use App\Services\Settings;
use App\Services\VoucherService;
use Livewire\Livewire;
use Tests\ErpTestCase;

class ChequeCollectionTest extends ErpTestCase
{
    private function accountId(string $number): int
    {
        return (int) Account::query()->where('number', $number)->value('id');
    }

    /** A posted cheque receipt voucher with one cheque per due date => amount. */
    private function postedChequeReceipt(array $cheques): Voucher
    {
        $voucher = Voucher::query()->create(['type' => 'receipt_cheque', 'branch_id' => 1, 'date' => '2026-03-01', 'description' => 'Cheques']);
        foreach ($cheques as $dueDate => $amount) {
            $voucher->lines()->create(['account_id' => $this->accountId('420101'), 'amount' => $amount, 'due_date' => $dueDate, 'cheque_no' => 'CH-'.$dueDate]);
        }

        return VoucherService::post($voucher);
    }

    public function test_collection_moves_selected_due_cheques_to_the_bank(): void
    {
        $this->postedChequeReceipt(['2026-03-05' => 100, '2026-03-20' => 250, '2026-05-01' => 900]);

        $rows = ChequeCollectionService::rows(1, '2026-03-01', '2026-03-31');
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['selected']);

        $selected = VoucherLine::query()->where('amount', 250)->value('id');
        $collection = ChequeCollection::query()->create([
            'branch_id' => 1, 'date' => '2026-03-31', 'from_date' => '2026-03-01', 'to_date' => '2026-03-31',
            'bank_account_id' => $this->accountId('110103'), 'cheque_line_ids' => [$selected],
        ]);
        ChequeCollectionService::recalculate($collection);
        $this->assertEquals(250, (float) $collection->total);

        ChequeCollectionService::post($collection);

        $entry = $collection->refresh()->journalEntry;
        $this->assertTrue($collection->is_posted);
        $this->assertEquals(250, (float) $entry->lines->firstWhere('account_id', $this->accountId('110103'))->debit);
        $this->assertEquals(250, (float) $entry->lines->firstWhere('account_id', Settings::account('cheques_under_collection'))->credit);
        $this->assertSame('collected', VoucherLine::query()->find($selected)->cheque_status);
        $this->assertSame('under_collection', VoucherLine::query()->where('amount', 100)->value('cheque_status'));

        $this->assertCount(1, ChequeCollectionService::rows(1, '2026-03-01', '2026-03-31', null, $collection));
    }

    public function test_receipt_voucher_with_collected_cheques_cannot_be_unposted_until_collection_is_reversed(): void
    {
        $voucher = $this->postedChequeReceipt(['2026-03-05' => 100]);
        $collection = ChequeCollection::query()->create([
            'branch_id' => 1, 'date' => '2026-03-31', 'from_date' => '2026-03-01', 'to_date' => '2026-03-31', 'bank_account_id' => $this->accountId('110103'),
        ]);
        ChequeCollectionService::post($collection);

        try {
            VoucherService::unpost($voucher);
            $this->fail('Expected the voucher unpost to be refused.');
        } catch (ErpException) {
            $this->assertTrue($voucher->refresh()->is_posted);
        }

        ChequeCollectionService::unpost($collection);
        $this->assertFalse($collection->refresh()->is_posted);
        $this->assertNull($collection->journal_entry_id);
        $this->assertSame('under_collection', $voucher->lines()->value('cheque_status'));
        VoucherService::unpost($voucher);
        $this->assertFalse($voucher->refresh()->is_posted);
    }

    public function test_empty_selection_cannot_be_posted(): void
    {
        $this->postedChequeReceipt(['2026-03-05' => 100]);
        $collection = ChequeCollection::query()->create([
            'branch_id' => 1, 'date' => '2026-03-31', 'from_date' => '2026-03-01', 'to_date' => '2026-03-31',
            'bank_account_id' => $this->accountId('110103'), 'cheque_line_ids' => [],
        ]);

        $this->expectException(ErpException::class);
        ChequeCollectionService::post($collection);
    }

    public function test_collection_form_loads_cheques_and_posts_the_ticked_ones(): void
    {
        $this->postedChequeReceipt(['2026-03-05' => 100, '2026-03-20' => 250]);

        $component = Livewire::test(CreateChequeCollection::class)
            ->fillForm(['branch_id' => 1, 'bank_account_id' => $this->accountId('110103'), 'post_now' => true])
            ->set('data.from_date', '2026-03-01')
            ->set('data.to_date', '2026-03-31');

        $rows = $component->get('data.cheque_rows');
        $this->assertCount(2, $rows);
        $this->assertEquals(350, (float) $component->get('data.total'));

        $firstKey = array_key_first($rows);
        $component->set("data.cheque_rows.{$firstKey}.selected", false)
            ->assertSet('data.total', 250.0)
            ->call('create')
            ->assertHasNoFormErrors();

        $collection = ChequeCollection::query()->latest('id')->firstOrFail();
        $this->assertTrue($collection->is_posted);
        $this->assertEquals(250, (float) $collection->total);
        $this->assertSame(1, VoucherLine::query()->where('cheque_status', 'collected')->count());
    }
}
