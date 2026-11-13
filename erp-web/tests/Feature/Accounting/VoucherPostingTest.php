<?php

namespace Tests\Feature\Accounting;

use App\Enums\VoucherType;
use App\Filament\Resources\Accounting\PaymentChequeVoucherResource\Pages\CreatePaymentChequeVoucher;
use App\Filament\Resources\Accounting\ReceiptVoucherResource\Pages\CreateReceiptVoucher;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Voucher;
use App\Services\Settings;
use App\Services\VoucherService;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ErpTestCase;

class VoucherPostingTest extends ErpTestCase
{
    private function accountId(string $number): int
    {
        return (int) Account::query()->where('number', $number)->value('id');
    }

    /** @return array<string, array{VoucherType, bool}> type => [type, header account is debited] */
    public static function voucherTypes(): array
    {
        return [
            'receipt' => [VoucherType::ReceiptCash, true],
            'cash payment' => [VoucherType::PaymentCash, false],
            'cheque payment' => [VoucherType::PaymentCheque, false],
            'credit notice' => [VoucherType::CreditNotice, false],
            'debit notice' => [VoucherType::DebitNotice, true],
            'cheque receipt' => [VoucherType::ReceiptCheque, true],
        ];
    }

    #[DataProvider('voucherTypes')]
    public function test_voucher_posts_header_and_lines_on_the_right_sides(VoucherType $type, bool $headerIsDebit): void
    {
        $header = $type === VoucherType::ReceiptCheque ? Settings::account('cheques_under_collection') : $this->accountId('110103');
        $voucher = Voucher::query()->create([
            'type' => $type->value, 'branch_id' => 1, 'date' => now(), 'account_id' => $header, 'amount' => 300, 'description' => 'Test',
        ]);
        $voucher->lines()->create(['account_id' => $this->accountId('520101'), 'amount' => 100, 'due_date' => now()]);
        $voucher->lines()->create(['account_id' => $this->accountId('520102'), 'amount' => 200, 'due_date' => now()]);

        VoucherService::post($voucher);

        $entry = $voucher->refresh()->journalEntry;
        $this->assertTrue($voucher->is_posted);
        $this->assertSame($type->entryType(), $entry->entry_type);
        $this->assertEquals(300, (float) $entry->total_debit);
        $this->assertEquals(300, (float) $entry->total_credit);

        $headerLine = $entry->lines->firstWhere('account_id', $header);
        $this->assertEquals($headerIsDebit ? 300 : 0, (float) $headerLine->debit);
        $this->assertEquals($headerIsDebit ? 0 : 300, (float) $headerLine->credit);

        $counter = $entry->lines->firstWhere('account_id', $this->accountId('520102'));
        $this->assertEquals($headerIsDebit ? 0 : 200, (float) $counter->debit);
        $this->assertEquals($headerIsDebit ? 200 : 0, (float) $counter->credit);
    }

    public function test_receipt_voucher_is_created_and_posted_from_the_form(): void
    {
        Livewire::test(CreateReceiptVoucher::class)
            ->fillForm([
                'account_id' => Settings::account('cash'),
                'amount' => 450,
                'description' => 'Customer payment',
                'received_by' => 'Ali',
                'post_now' => true,
            ])
            ->set('data.lines', ['a' => ['account_id' => $this->accountId('420101'), 'amount' => 450]])
            ->call('create')
            ->assertHasNoFormErrors();

        $voucher = Voucher::query()->where('type', 'receipt_cash')->latest('id')->firstOrFail();
        $this->assertTrue($voucher->is_posted);
        $this->assertEquals(450, (float) $voucher->amount);
        $this->assertSame('1', $voucher->number);
    }

    public function test_header_amount_must_equal_the_lines_total(): void
    {
        Livewire::test(CreateReceiptVoucher::class)
            ->fillForm(['account_id' => Settings::account('cash'), 'amount' => 500, 'description' => 'Mismatch'])
            ->set('data.lines', ['a' => ['account_id' => $this->accountId('420101'), 'amount' => 450]])
            ->call('create')
            ->assertHasFormErrors(['amount']);

        $this->assertSame(0, Voucher::query()->count());
    }

    public function test_cheque_payment_voucher_requires_bank_and_due_date(): void
    {
        Livewire::test(CreatePaymentChequeVoucher::class)
            ->fillForm(['account_id' => $this->accountId('110103'), 'amount' => 100, 'description' => 'Cheque', 'bank_id' => null, 'due_date' => null])
            ->set('data.lines', ['a' => ['account_id' => $this->accountId('520102'), 'amount' => 100]])
            ->call('create')
            ->assertHasFormErrors(['bank_id' => 'required', 'due_date' => 'required']);

        $bank = Bank::query()->create(['code' => '1', 'name_ar' => 'بنك', 'account_id' => $this->accountId('110103')]);
        Livewire::test(CreatePaymentChequeVoucher::class)
            ->fillForm(['bank_id' => $bank->id])
            ->assertSet('data.account_id', $this->accountId('110103'));
    }

    public function test_cheque_receipt_defaults_to_cheques_under_collection(): void
    {
        $voucher = Voucher::query()->create(['type' => 'receipt_cheque', 'branch_id' => 1, 'date' => now(), 'description' => 'Cheque']);
        $voucher->lines()->create(['account_id' => $this->accountId('420101'), 'amount' => 50, 'due_date' => now()]);
        VoucherService::recalculate($voucher);

        $this->assertSame(Settings::account('cheques_under_collection'), (int) $voucher->account_id);
        $this->assertSame('under_collection', $voucher->cheque_status);
        $this->assertSame('under_collection', $voucher->lines()->value('cheque_status'));
    }
}
