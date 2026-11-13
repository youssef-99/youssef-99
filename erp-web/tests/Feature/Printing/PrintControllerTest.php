<?php

namespace Tests\Feature\Printing;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ChequeCollection;
use App\Models\Customer;
use App\Models\Group;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\StockDocument;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Warehouse;
use App\Printing\AmountInWords;
use App\Services\ManualJournalService;
use App\Services\Settings;
use App\Services\StockDocumentService;
use App\Services\VoucherService;
use App\Services\Zatca\ZatcaService;
use Tests\ErpTestCase;

class PrintControllerTest extends ErpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_sales_invoice_prints_as_a_tax_invoice_with_the_zatca_qr(): void
    {
        $invoice = SalesDocument::query()->where('type', 'sales_invoice')->where('is_posted', true)->firstOrFail();

        $this->get(route('print', ['type' => 'sales', 'id' => $invoice->id]))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('فاتورة ضريبية')
            ->assertDontSee('فاتورة ضريبية مبسطة')
            ->assertSee($invoice->number)
            ->assertSee('data-qr="'.$invoice->zatca_qr.'"', false)
            ->assertSee('qrcode.min.js', false)
            ->assertSee(number_format((float) $invoice->grand_total, 2));
    }

    public function test_pos_invoice_prints_as_a_simplified_tax_invoice(): void
    {
        $pos = SalesDocument::query()->where('type', 'pos_invoice')->where('is_posted', true)->firstOrFail();

        $this->get(route('print', ['type' => 'sales', 'id' => $pos->id]))
            ->assertOk()
            ->assertSee('فاتورة ضريبية مبسطة')
            ->assertSee('data-qr=', false);
    }

    public function test_pos_invoice_prints_the_80mm_thermal_receipt(): void
    {
        $pos = SalesDocument::query()->where('type', 'pos_invoice')->where('is_posted', true)->firstOrFail();

        $this->get(route('print', ['type' => 'sales', 'id' => $pos->id, 'layout' => '80mm']))
            ->assertOk()
            ->assertSee('layout-80mm', false)
            ->assertSee('size: 80mm auto', false)
            ->assertSee('data-qr="'.$pos->zatca_qr.'"', false);
    }

    public function test_missing_qr_is_generated_for_posted_documents(): void
    {
        $pos = SalesDocument::query()->where('type', 'pos_invoice')->where('is_posted', true)->firstOrFail();
        $pos->forceFill(['zatca_qr' => null])->saveQuietly();
        $expected = app(ZatcaService::class)->qr($pos->fresh(['branch.company']));

        $this->get(route('print', ['type' => 'sales', 'id' => $pos->id]))
            ->assertOk()
            ->assertSee('data-qr="'.$expected.'"', false);

        $this->assertNull($pos->fresh()->zatca_qr);
    }

    public function test_sales_return_prints_as_a_credit_note_and_quotation_has_no_qr(): void
    {
        $branch = Branch::query()->firstOrFail();
        $item = Item::query()->where('code', '1005')->firstOrFail();

        $return = SalesDocument::query()->create(['type' => 'sales_return', 'branch_id' => $branch->id, 'warehouse_id' => Warehouse::query()->value('id'), 'date' => now()]);
        $return->lines()->create(['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 29, 'tax_percent' => 15]);

        $this->get(route('print', ['type' => 'sales', 'id' => $return->id]))
            ->assertOk()
            ->assertSee('إشعار دائن');

        $quotation = SalesDocument::query()->create(['type' => 'quotation', 'branch_id' => $branch->id, 'date' => now()]);

        $this->get(route('print', ['type' => 'sales', 'id' => $quotation->id]))
            ->assertOk()
            ->assertDontSee('data-qr=', false);
    }

    public function test_english_locale_prints_left_to_right(): void
    {
        $this->admin->update(['locale' => 'en']);
        $invoice = SalesDocument::query()->where('type', 'sales_invoice')->firstOrFail();

        $this->withSession(['locale' => 'en'])
            ->get(route('print', ['type' => 'sales', 'id' => $invoice->id, 'autoprint' => 1]))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Tax Invoice')
            ->assertSee('window.print(); }, 300)', false);
    }

    public function test_purchase_invoice_and_goods_receipt_note(): void
    {
        $purchase = PurchaseDocument::query()->where('type', 'purchase_invoice')->firstOrFail();
        $line = $purchase->lines()->whereNotNull('batch_no')->firstOrFail();

        $this->get(route('print', ['type' => 'purchase', 'id' => $purchase->id]))
            ->assertOk()
            ->assertSee('فاتورة مشتريات')
            ->assertSee(number_format((float) $purchase->grand_total, 2));

        $this->get(route('print', ['type' => 'purchase-grn', 'id' => $purchase->id]))
            ->assertOk()
            ->assertSee('إذن إدخال بضاعة')
            ->assertSee($line->batch_no)
            ->assertDontSee(number_format((float) $purchase->grand_total, 2));
    }

    public function test_stock_document(): void
    {
        $item = Item::query()->where('code', '1005')->firstOrFail();
        $doc = StockDocument::query()->create([
            'type' => 'positive_adjustment', 'branch_id' => Branch::query()->value('id'), 'warehouse_id' => Warehouse::query()->value('id'), 'date' => now(),
        ]);
        $doc->lines()->create(['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 4, 'price' => 18]);
        StockDocumentService::post($doc);

        $this->get(route('print', ['type' => 'stock', 'id' => $doc->id]))
            ->assertOk()
            ->assertSee($item->name_ar)
            ->assertSee('72.00');
    }

    public function test_voucher_prints_amount_in_words(): void
    {
        $customer = Customer::query()->firstOrFail();
        $voucher = Voucher::query()->create([
            'type' => 'receipt_cash', 'branch_id' => Branch::query()->value('id'), 'date' => now(), 'account_id' => Settings::account('cash'), 'received_by' => 'Test',
        ]);
        $voucher->lines()->create(['account_id' => $customer->account_id, 'amount' => 1250.50, 'description' => 'Payment on account']);
        VoucherService::post($voucher);

        $this->get(route('print', ['type' => 'voucher', 'id' => $voucher->id]))
            ->assertOk()
            ->assertSee('سند قبض')
            ->assertSee('1,250.50')
            ->assertSee(AmountInWords::format(1250.50, 'ar'));
    }

    public function test_journal_entry_shows_debit_and_credit_totals(): void
    {
        $invoice = SalesDocument::query()->where('type', 'sales_invoice')->firstOrFail();
        $entry = $invoice->journalEntry;

        $this->get(route('print', ['type' => 'journal', 'id' => $entry->id]))
            ->assertOk()
            ->assertSee('سند قيد')
            ->assertSee(number_format((float) $entry->total_debit, 2))
            ->assertSee('0.00');

        $manual = JournalEntry::query()->create(['branch_id' => Branch::query()->value('id'), 'date' => now(), 'description' => 'Manual test']);
        $manual->lines()->create(['account_id' => Settings::account('cash'), 'debit' => 100, 'credit' => 0, 'sort' => 1]);
        $manual->lines()->create(['account_id' => Settings::account('sales'), 'debit' => 0, 'credit' => 100, 'sort' => 2]);
        ManualJournalService::post($manual);

        $this->get(route('print', ['type' => 'journal', 'id' => $manual->id]))->assertOk()->assertSee('Manual test');
    }

    public function test_cheque_collection_lists_collected_cheques(): void
    {
        $customer = Customer::query()->firstOrFail();
        $branchId = Branch::query()->value('id');
        $voucher = Voucher::query()->create(['type' => 'receipt_cheque', 'branch_id' => $branchId, 'date' => now()]);
        $voucher->lines()->create(['account_id' => $customer->account_id, 'amount' => 500, 'cheque_no' => 'CHQ-778899', 'due_date' => now()->addDays(2)]);
        VoucherService::post($voucher);

        $collection = ChequeCollection::query()->create([
            'branch_id' => $branchId, 'date' => now()->addDays(3), 'from_date' => now()->subDay(), 'to_date' => now()->addDays(5),
            'bank_account_id' => Account::query()->where('number', '110103')->value('id'),
        ]);

        $this->get(route('print', ['type' => 'cheque-collection', 'id' => $collection->id]))
            ->assertOk()
            ->assertSee('CHQ-778899');

        VoucherService::postCollection($collection);

        $this->get(route('print', ['type' => 'cheque-collection', 'id' => $collection->id]))
            ->assertOk()
            ->assertSee('CHQ-778899')
            ->assertSee('500.00');
    }

    public function test_chart_of_accounts_is_printed_as_an_indented_tree(): void
    {
        $this->get(route('print', ['type' => 'chart-of-accounts', 'id' => 0]))
            ->assertOk()
            ->assertSee('دليل الحسابات')
            ->assertSeeInOrder(['الأصول', 'الأصول المتداولة', 'النقدية والبنوك', 'الصندوق الرئيسي'])
            ->assertSee('padding-inline-start: 53px', false);
    }

    public function test_unknown_type_or_record_returns_404(): void
    {
        $this->get(route('print', ['type' => 'nonsense', 'id' => 1]))->assertNotFound();
        $this->get(route('print', ['type' => 'sales', 'id' => 999999]))->assertNotFound();
        $this->get('/print/sales/abc')->assertNotFound();
    }

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $invoice = SalesDocument::query()->where('type', 'sales_invoice')->firstOrFail();
        $group = Group::query()->create(['code' => 'P1', 'name_ar' => 'مستخدمو المبيعات', 'permissions' => ['sales.customers.view']]);
        $user = User::factory()->create(['group_id' => $group->id]);

        $this->actingAs($user)->get(route('print', ['type' => 'sales', 'id' => $invoice->id]))->assertForbidden();

        $group->update(['permissions' => ['sales.sales-invoices.view']]);

        $this->actingAs($user->fresh())->get(route('print', ['type' => 'sales', 'id' => $invoice->id]))->assertOk();
        $this->actingAs($user->fresh())->get(route('print', ['type' => 'chart-of-accounts', 'id' => 0]))->assertForbidden();
    }
}
