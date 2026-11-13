<?php

namespace Database\Seeders\Demo;

use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\ChequeCollection;
use App\Models\City;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\FinancingContract;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\FinancingContractService;
use App\Services\ManualJournalService;
use App\Services\Settings;
use App\Services\VoucherService;
use Illuminate\Database\Seeder;

/**
 * Accounting demo data: a bank, cost centers, a manual journal voucher, one voucher of each type,
 * a draft cheque collection and a posted murabaha contract.
 */
class AccountingDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Bank::query()->exists()) {
            return;
        }

        $branch = Branch::query()->firstOrFail();
        $account = fn (string $number): ?int => Account::query()->where('number', $number)->value('id');
        $cash = Settings::account('cash') ?? $account('110101');
        $bankAccount = $account('110103');
        $rent = $account('520102');
        $customerAccount = Customer::query()->value('account_id');
        $supplierAccount = Supplier::query()->value('account_id');

        $bank = Bank::query()->create([
            'code' => '1', 'name_ar' => 'مصرف الراجحي', 'name_en' => 'Al Rajhi Bank', 'iban' => 'SA0380000000608010167519',
            'account_no' => '608010167519', 'swift_code' => 'RJHISARI', 'city_id' => City::query()->value('id'), 'account_id' => $bankAccount,
        ]);
        $head = CostCenter::query()->create(['code' => '1', 'name_ar' => 'الإدارة العامة', 'name_en' => 'Head Office']);
        $costCenter = CostCenter::query()->create(['code' => '2', 'name_ar' => 'صيدلية الفرع الرئيسي', 'name_en' => 'Main Branch Pharmacy', 'parent_id' => $head->id]);

        $journal = JournalEntry::query()->create([
            'branch_id' => $branch->id, 'date' => now()->subDays(5), 'entry_type' => 'manual', 'description' => 'إيجار الشهر',
        ]);
        $journal->lines()->create(['account_id' => $rent, 'debit' => 5000, 'credit' => 0, 'cost_center_id' => $costCenter->id, 'description' => 'إيجار الشهر', 'sort' => 0]);
        $journal->lines()->create(['account_id' => $cash, 'debit' => 0, 'credit' => 5000, 'description' => 'إيجار الشهر', 'sort' => 1]);
        ManualJournalService::post($journal);

        $vouchers = [
            [VoucherType::ReceiptCash, $cash, $customerAccount, 1000, ['received_by' => 'محمد أحمد']],
            [VoucherType::PaymentCash, $cash, $rent, 2000, ['pay_to' => 'مكتب العقار']],
            [VoucherType::PaymentCheque, $bankAccount, $supplierAccount, 3000, ['bank_id' => $bank->id, 'cheque_no' => '100001', 'due_date' => now()->addDays(15), 'pay_to' => 'شركة نهدي للأدوية']],
            [VoucherType::CreditNotice, $customerAccount, Settings::account('sales_discount'), 150, []],
            [VoucherType::DebitNotice, $supplierAccount, Settings::account('purchase_discount'), 200, []],
        ];
        foreach ($vouchers as [$type, $header, $counter, $amount, $extra]) {
            if (! $header || ! $counter) {
                continue;
            }
            $voucher = Voucher::query()->create([
                'type' => $type->value, 'branch_id' => $branch->id, 'date' => now()->subDays(2), 'account_id' => $header,
                'amount' => $amount, 'description' => $type->label(),
            ] + $extra);
            $voucher->lines()->create(['account_id' => $counter, 'amount' => $amount, 'description' => $type->label()]);
            VoucherService::post($voucher);
        }

        if ($customerAccount) {
            $chequeReceipt = Voucher::query()->create([
                'type' => VoucherType::ReceiptCheque->value, 'branch_id' => $branch->id, 'date' => now()->subDay(),
                'amount' => 1500, 'description' => VoucherType::ReceiptCheque->label(), 'received_by' => 'مستوصف الشفاء',
            ]);
            $chequeReceipt->lines()->create([
                'account_id' => $customerAccount, 'amount' => 1500, 'bank_id' => $bank->id, 'cheque_no' => '554433', 'due_date' => now()->addDays(3),
            ]);
            VoucherService::post($chequeReceipt);

            ChequeCollection::query()->create([
                'branch_id' => $branch->id, 'date' => now(), 'from_date' => now()->startOfMonth(), 'to_date' => now()->addMonth()->endOfMonth(),
                'bank_account_id' => $bankAccount, 'total' => 1500,
            ]);
        }

        $contract = FinancingContract::query()->create([
            'branch_id' => $branch->id, 'bank_id' => $bank->id, 'contract_date' => now()->subMonth(), 'first_installment_date' => now(),
            'murabaha_amount' => 120000, 'bank_profit_margin' => 5, 'installments_count' => 12,
            'funding_account_id' => $bankAccount, 'loan_account_id' => $account('210401'), 'interest_account_id' => $account('520105'),
        ]);
        FinancingContractService::recalculate($contract);
        FinancingContractService::post($contract);
        FinancingContractService::payInstallment($contract->installments()->first(), $bankAccount, now());
    }
}
