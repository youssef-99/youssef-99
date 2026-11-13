<?php

namespace Tests\Feature\Accounting;

use App\Exceptions\ErpException;
use App\Filament\Resources\Accounting\FinancingContractResource\Pages\CreateFinancingContract;
use App\Models\Account;
use App\Models\Bank;
use App\Models\FinancingContract;
use App\Services\FinancingContractService;
use Livewire\Livewire;
use Tests\ErpTestCase;

class FinancingContractTest extends ErpTestCase
{
    private function accountId(string $number): int
    {
        return (int) Account::query()->where('number', $number)->value('id');
    }

    public function test_schedule_installments_sum_to_the_total_payable(): void
    {
        $schedule = FinancingContractService::schedule(100000, 7.5, 7, '2026-01-31');

        $this->assertCount(7, $schedule);
        $this->assertEquals(107500.00, round(array_sum(array_column($schedule, 'amount')), 2));
        $this->assertEquals(15357.14, $schedule[0]['amount']);
        $this->assertEquals(15357.16, $schedule[6]['amount']);
        $this->assertSame('2026-02-28', $schedule[1]['due_date']);
    }

    public function test_contract_form_generates_schedule_and_posts_the_contract_entry(): void
    {
        $bank = Bank::query()->create(['code' => '1', 'name_ar' => 'بنك', 'account_id' => $this->accountId('110103')]);

        Livewire::test(CreateFinancingContract::class)
            ->fillForm([
                'bank_id' => $bank->id,
                'contract_date' => '2026-01-10',
                'first_installment_date' => '2026-02-10',
                'murabaha_amount' => 60000,
                'bank_profit_margin' => 10,
                'installments_count' => 12,
                'funding_account_id' => $this->accountId('110103'),
                'post_now' => true,
            ])
            ->assertSet('data.installment_amount', 5500.0)
            ->call('create')
            ->assertHasNoFormErrors();

        $contract = FinancingContract::query()->latest('id')->firstOrFail();
        $this->assertTrue($contract->is_posted);
        $this->assertCount(12, $contract->installments);
        $this->assertEquals(66000, (float) $contract->installments->sum('amount'));

        $lines = $contract->journalEntry->lines;
        $this->assertEquals(60000, (float) $lines->firstWhere('account_id', $this->accountId('110103'))->debit);
        $this->assertEquals(6000, (float) $lines->firstWhere('account_id', $this->accountId('520105'))->debit);
        $this->assertEquals(66000, (float) $lines->firstWhere('account_id', $this->accountId('210401'))->credit);
    }

    public function test_installment_payment_posts_entry_and_blocks_unposting_the_contract(): void
    {
        $contract = FinancingContract::query()->create([
            'branch_id' => 1, 'contract_date' => '2026-01-10', 'murabaha_amount' => 1200, 'bank_profit_margin' => 0, 'installments_count' => 3,
            'funding_account_id' => $this->accountId('110103'), 'loan_account_id' => $this->accountId('210401'), 'interest_account_id' => $this->accountId('520105'),
        ]);
        FinancingContractService::recalculate($contract);
        FinancingContractService::post($contract);
        $installment = $contract->installments()->first();

        FinancingContractService::payInstallment($installment, $this->accountId('110101'), '2026-02-10');

        $entry = $installment->refresh()->journalEntry;
        $this->assertTrue($installment->is_paid);
        $this->assertEquals(400, (float) $entry->lines->firstWhere('account_id', $this->accountId('210401'))->debit);
        $this->assertEquals(400, (float) $entry->lines->firstWhere('account_id', $this->accountId('110101'))->credit);
        $this->assertNotNull($contract->refresh()->journalEntry);

        try {
            FinancingContractService::unpost($contract);
            $this->fail('Expected unpost to be refused.');
        } catch (ErpException) {
            $this->assertTrue($contract->refresh()->is_posted);
        }

        FinancingContractService::cancelInstallmentPayment($installment);
        $this->assertModelMissing($entry);
        FinancingContractService::unpost($contract);
        $this->assertFalse($contract->refresh()->is_posted);
    }

    public function test_installments_cannot_be_paid_before_the_contract_is_posted(): void
    {
        $contract = FinancingContract::query()->create(['contract_date' => '2026-01-10', 'murabaha_amount' => 1000, 'installments_count' => 2]);
        FinancingContractService::recalculate($contract);

        $this->expectException(ErpException::class);
        FinancingContractService::payInstallment($contract->installments()->first(), $this->accountId('110101'));
    }
}
