<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\Accreditation;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\SalesDocument;
use App\Reports\Accounts\AccountStatementReport;
use App\Reports\Accounts\AccountsTrialBalanceReport;
use App\Reports\Accounts\TrialBalanceAnalysisReport;
use App\Reports\Accreditations\AccreditationStatementReport;
use App\Reports\CostCenters\CostCenterStatementReport;
use App\Reports\CostCenters\CostCenterTrialBalanceReport;
use App\Reports\Customers\CustomerStatementReport;
use App\Reports\FinalAccounts\BudgetReport;
use App\Reports\FinalAccounts\IncomeListReport;
use App\Reports\ReportResult;
use App\Reports\Support\AgingBuilder;
use App\Reports\Support\Ledger;
use App\Reports\Vat\AllVatReport;
use App\Reports\Vat\VatReport;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Tests\ErpTestCase;

/**
 * Accounting math of the financial reports against hand-built and demo journal entries.
 */
class FinancialReportsTest extends ErpTestCase
{
    public function test_account_statement_opening_and_running_balances(): void
    {
        $this->entry('2026-01-10', [['110101', 1000, 0], ['310101', 0, 1000]]);   // before the period
        $this->entry('2026-02-05', [['110101', 0, 300], ['520102', 300, 0]]);     // before the period
        $this->entry('2026-03-01', [['110101', 500, 0], ['410101', 0, 500]]);
        $this->entry('2026-03-15', [['110101', 0, 200], ['520101', 200, 0]]);
        $this->entry('2026-03-31', [['110101', 50, 0], ['410101', 0, 50]]);       // on the "to" day: included
        $this->entry('2026-03-20', [['110101', 9999, 0], ['410101', 0, 9999]], posted: false);
        $this->entry('2026-04-01', [['110101', 7777, 0], ['410101', 0, 7777]]);   // after the period

        $result = (new AccountStatementReport)->run([
            'account_id' => $this->accountId('110101'), 'from' => '2026-03-01', 'to' => '2026-03-31',
        ]);

        $rows = $result->rows;
        $this->assertCount(4, $rows);
        $this->assertSame('opening', $rows[0]['type']);
        $this->assertEquals(700.0, $rows[0]['cells']['debit']);
        $this->assertEquals(700.0, $rows[0]['cells']['balance']);
        $this->assertEquals([1200.0, 1000.0, 1050.0], array_map(fn ($r) => $r['cells']['balance'], array_slice($rows, 1)));

        [$total, $movement] = $result->footer;
        $this->assertEquals(['debit' => 1250.0, 'credit' => 200.0, 'balance' => 1050.0], array_intersect_key($total['cells'], array_flip(['debit', 'credit', 'balance'])));
        $this->assertEquals(['debit' => 550.0, 'credit' => 200.0, 'balance' => 350.0], array_intersect_key($movement['cells'], array_flip(['debit', 'credit', 'balance'])));
    }

    public function test_account_statement_of_a_parent_includes_child_accounts(): void
    {
        $this->entry('2026-03-01', [['110101', 100, 0], ['410101', 0, 100]]);
        $this->entry('2026-03-02', [['110103', 40, 0], ['110101', 0, 40]]);
        $this->entry('2026-03-03', [['110102', 25, 0], ['410101', 0, 25]]);

        $result = (new AccountStatementReport)->run([
            'account_id' => $this->accountId('1101'), 'from' => '2026-01-01', 'to' => '2026-12-31',
        ]);

        $this->assertContains('account', array_map(fn ($c) => $c->key, $result->columns));
        $this->assertCount(5, $result->rows); // opening + 4 lines on child accounts
        $this->assertEquals(125.0, $result->footer[0]['cells']['balance']);
    }

    public function test_trial_balances_balance_at_every_level(): void
    {
        $this->seedDemo();
        $this->entry(today()->startOfYear()->subDays(5)->toDateString(), [['110101', 5000, 0], ['310101', 0, 5000]]);
        $from = today()->startOfYear()->toDateString();
        $to = today()->toDateString();
        $maxLevel = (int) Account::query()->max('level');

        foreach (range(1, $maxLevel) as $level) {
            $total = (new AccountsTrialBalanceReport)->run(['level' => $level, 'from' => $from, 'to' => $to])->footer[0]['cells'];
            $this->assertGreaterThan(0, $total['debit'], "level {$level}");
            $this->assertEquals($total['opening_debit'], $total['opening_credit'], "opening level {$level}");
            $this->assertEquals($total['debit'], $total['credit'], "movement level {$level}");
            $this->assertEquals($total['closing_debit'], $total['closing_credit'], "closing level {$level}");
            $this->assertEquals(5000.0, $total['opening_debit'], "opening level {$level}");
        }

        $analysis = (new TrialBalanceAnalysisReport)->run(['level' => $maxLevel, 'from' => $from, 'to' => $to])->footer[0]['cells'];
        $this->assertEquals(0.0, $analysis['closing']);
        $this->assertEquals($analysis['debit'], $analysis['credit']);
    }

    public function test_balance_sheet_balances_and_matches_income_statement(): void
    {
        $this->seedDemo();
        $to = today()->toDateString();

        $sheet = (new BudgetReport)->run(['to' => $to, 'level' => 3]);
        [$assets, $liabilitiesAndEquity] = $sheet->footer;
        $this->assertGreaterThan(0, $assets['cells']['amount']);
        $this->assertEquals($assets['cells']['amount'], $liabilitiesAndEquity['cells']['amount']);

        $income = (new IncomeListReport)->run(['from' => '2000-01-01', 'to' => $to]);
        $netProfit = $income->footer[0]['cells']['amount'];
        $sheetProfit = collect($sheet->rows)->first(fn ($r) => isset($r['cells']['name']) && $r['type'] === 'row' && ! isset($r['cells']['number']))['cells']['amount'];
        $this->assertEquals($netProfit, $sheetProfit);
        $this->assertGreaterThan(0, $this->rowAmount($income, __('rpt.Total :name', ['name' => __('rpt.Net Sales')])));
    }

    public function test_vat_reports_match_documents_and_the_ledger(): void
    {
        $this->seedDemo();
        $from = today()->startOfYear()->toDateString();
        $to = today()->toDateString();

        $invoices = SalesDocument::query()->where('is_posted', true)->whereIn('type', ['sales_invoice', 'pos_invoice', 'cash_sales_invoice', 'debit_note']);
        $returns = SalesDocument::query()->where('is_posted', true)->whereIn('type', ['sales_return', 'pos_return', 'cash_sales_return', 'credit_note']);
        $invoiceTax = round((float) (clone $invoices)->sum('tax_total'), 2);
        $salesTax = round($invoiceTax - (float) (clone $returns)->sum('tax_total'), 2);
        $salesNet = round((float) (clone $invoices)->sum('net_total'), 2);
        $this->assertGreaterThan(0, $salesTax);

        $all = (new AllVatReport)->run(['from' => $from, 'to' => $to]);
        [$output, $input, $net] = $all->footer;
        $this->assertEquals($salesTax, $output['cells']['vat']);
        $this->assertEquals(round($output['cells']['vat'] - $input['cells']['vat'], 2), $net['cells']['vat']);

        // Output / input VAT agree with the period movement on the VAT accounts in the ledger.
        $movement = fn (string $key): float => round((float) Ledger::between(Ledger::forAccounts([Settings::account($key)]), $from, $to)->sum(DB::raw('l.debit - l.credit')), 2);
        $this->assertEquals($output['cells']['vat'], -$movement('vat_output'));
        $this->assertEquals($input['cells']['vat'], $movement('vat_input'));

        $return = (new VatReport)->run(['from' => $from, 'to' => $to]);
        $boxes = collect([...$return->rows, ...$return->footer])->filter(fn ($r) => isset($r['cells']['box']))->keyBy(fn ($r) => (string) $r['cells']['box'])->map(fn ($r) => $r['cells']);
        $this->assertEquals($salesTax, $boxes['1']['vat']);
        $this->assertEquals($salesNet, round($boxes['6']['amount'], 2));
        $this->assertLessThan(0, $boxes['1']['adjustment']);
        $this->assertGreaterThan(0, $boxes['2']['amount']); // the demo's zero-rated medicine (VATEX-SA-HEA)
        $this->assertEquals(round($boxes['1']['amount'] + $boxes['2']['amount'] + $boxes['3']['amount'] + $boxes['4']['amount'] + $boxes['5']['amount'], 2), $boxes['6']['amount']);
        $this->assertEquals(round($boxes['6']['vat'] - $boxes['12']['vat'], 2), $boxes['16']['vat']);
        $this->assertEquals($net['cells']['vat'], $boxes['16']['vat']);
    }

    public function test_customer_statement_closing_equals_the_account_balance(): void
    {
        $this->seedDemo();
        $customer = Customer::query()->firstOrFail();
        $result = (new CustomerStatementReport)->run(['customer_id' => $customer->id, 'from' => today()->startOfYear()->toDateString(), 'to' => today()->toDateString()]);

        $balance = Ledger::closingBalance(Ledger::forAccounts([$customer->account_id]), today()->toDateString());
        $this->assertNotEquals(0.0, $balance);
        $this->assertEquals($balance, $result->footer[0]['cells']['balance']);
    }

    public function test_debt_ageing_allocates_the_balance_to_the_newest_charges(): void
    {
        $buckets = AgingBuilder::allocate(1000, [
            ['date' => '2026-01-01', 'amount' => 600],  // 150 days old
            ['date' => '2026-05-01', 'amount' => 300],  // 30 days old
            ['date' => '2026-05-21', 'amount' => 200],  // 10 days old
        ], '2026-05-31', 30);

        $this->assertEquals(['bucket_0' => 500.0, 'bucket_1' => 0.0, 'bucket_2' => 0.0, 'bucket_3' => 0.0, 'bucket_4' => 500.0], $buckets);
        $this->assertEquals(['0 - 30', '31 - 60', '61 - 90', '91 - 120'], array_slice(AgingBuilder::labels(30), 0, 4));
        $this->assertEquals(-50.0, AgingBuilder::allocate(-50, [], '2026-05-31', 30)['bucket_0']);
    }

    public function test_cost_center_and_letter_of_credit_statements(): void
    {
        $parent = CostCenter::query()->create(['code' => '1', 'name_ar' => 'الإدارة']);
        $child = CostCenter::query()->create(['code' => '101', 'name_ar' => 'الفرع', 'parent_id' => $parent->id]);
        $entry = $this->entry('2026-03-01', [['520102', 400, 0], ['110101', 0, 400]]);
        $entry->lines()->where('account_id', $this->accountId('520102'))->update(['cost_center_id' => $child->id]);

        $statement = (new CostCenterStatementReport)->run(['cost_center_id' => $parent->id, 'from' => '2026-01-01', 'to' => '2026-12-31']);
        $this->assertEquals(400.0, $statement->footer[0]['cells']['balance']);
        $this->assertCount(2, $statement->rows);

        $trial = (new CostCenterTrialBalanceReport)->run(['from' => '2026-01-01', 'to' => '2026-12-31']);
        $this->assertEquals(400.0, $trial->footer[0]['cells']['closing_debit']);

        $lc = Accreditation::query()->create(['number' => 'LC-1', 'lc_account_id' => $this->accountId('210301')]);
        $fee = $this->entry('2026-03-05', [['520103', 75, 0], ['210301', 0, 75]]);
        $fee->lines()->update(['accreditation_id' => $lc->id]);
        $this->entry('2026-03-06', [['520103', 10, 0], ['210301', 0, 10]]); // untagged: not part of LC-1

        $lcStatement = (new AccreditationStatementReport)->run(['accreditation_id' => $lc->id, 'from' => '2026-01-01', 'to' => '2026-12-31']);
        $this->assertCount(2, $lcStatement->rows); // opening + the L/C account line only
        $this->assertEquals(-75.0, $lcStatement->footer[0]['cells']['balance']);
        $this->get(route('report.viewer', ['report' => 'accreditations/accreditation-statement', 'accreditation_id' => $lc->id, 'from' => '2026-01-01', 'to' => '2026-12-31']))->assertOk();
    }

    /** @param  array<int, array{0: string, 1: float|int, 2: float|int}>  $lines  [account number, debit, credit] */
    protected function entry(string $date, array $lines, bool $posted = true): JournalEntry
    {
        $entry = JournalEntry::query()->create([
            'branch_id' => Branch::query()->value('id'),
            'date' => $date,
            'entry_type' => 'manual',
            'description' => 'Test entry',
            'is_posted' => $posted,
        ]);
        foreach ($lines as $i => [$number, $debit, $credit]) {
            $entry->lines()->create(['account_id' => $this->accountId($number), 'debit' => $debit, 'credit' => $credit, 'sort' => $i]);
        }

        return $entry->recalculate();
    }

    protected function accountId(string $number): int
    {
        return (int) Account::query()->where('number', $number)->value('id');
    }

    protected function rowAmount(ReportResult $result, string $name): float
    {
        foreach ($result->rows as $row) {
            if (($row['cells']['name'] ?? null) === $name) {
                return (float) $row['cells']['amount'];
            }
        }

        $this->fail("Row {$name} not found");
    }
}
