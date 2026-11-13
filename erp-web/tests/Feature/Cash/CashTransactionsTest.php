<?php

namespace Tests\Feature\Cash;

use App\Filament\Resources\Cash\CashCustomerResource\Pages\ManageCashCustomers;
use App\Filament\Resources\Cash\CashPurchaseInvoiceResource;
use App\Filament\Resources\Cash\CashPurchaseInvoiceResource\Pages\EditCashPurchaseInvoice;
use App\Filament\Resources\Cash\CashPurchaseInvoiceResource\Pages\ListCashPurchaseInvoices;
use App\Filament\Resources\Cash\CashSalesInvoiceResource\Pages\EditCashSalesInvoice;
use App\Filament\Resources\Cash\CashSalesInvoiceResource\Pages\ListCashSalesInvoices;
use App\Filament\Resources\Cash\CashSalesReturnResource\Pages\ListCashSalesReturns;
use App\Filament\Resources\Cash\CashSupplierResource\Pages\ManageCashSuppliers;
use App\Filament\Resources\Cash\CashZatcaSalesInvoiceResource\Pages\ListCashZatcaSalesInvoices;
use App\Models\CashCustomer;
use App\Models\CashSupplier;
use App\Models\City;
use App\Models\Item;
use App\Models\PurchaseDocument;
use App\Models\Region;
use App\Models\SalesDocument;
use App\Services\Settings;
use App\Services\StockService;
use Livewire\Livewire;
use Tests\ErpTestCase;

class CashTransactionsTest extends ErpTestCase
{
    public function test_cash_supplier_requires_a_vat_number(): void
    {
        Livewire::test(ManageCashSuppliers::class)
            ->callAction('create', data: ['code' => '5', 'name_ar' => 'مورد نقدي', 'vat_no' => null])
            ->assertHasActionErrors(['vat_no' => 'required']);

        Livewire::test(ManageCashSuppliers::class)
            ->callAction('create', data: ['code' => '5', 'name_ar' => 'مورد نقدي', 'vat_no' => '12345'])
            ->assertHasActionErrors(['vat_no' => 'regex']);

        Livewire::test(ManageCashSuppliers::class)
            ->callAction('create', data: ['code' => '5', 'name_ar' => 'مورد نقدي', 'vat_no' => '300000000000003'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, CashSupplier::query()->count());
    }

    public function test_cash_customer_requires_country_city_and_region(): void
    {
        Livewire::test(ManageCashCustomers::class)
            ->callAction('create', data: ['code' => '1', 'name_ar' => 'عميل', 'country_id' => null])
            ->assertHasActionErrors(['country_id' => 'required', 'city_id' => 'required', 'region_id' => 'required']);

        $city = City::query()->firstOrFail();
        Livewire::test(ManageCashCustomers::class)
            ->callAction('create', data: ['code' => '1', 'name_ar' => 'عميل', 'country_id' => $city->country_id, 'city_id' => $city->id, 'region_id' => Region::query()->value('id')])
            ->assertHasNoActionErrors();

        $this->assertSame(1, CashCustomer::query()->count());
    }

    public function test_cash_purchase_invoice_is_credited_to_the_cash_account(): void
    {
        $this->seedDemo();
        $item = Item::query()->where('code', '1006')->firstOrFail();
        $before = StockService::balance($item->id);
        $supplier = CashSupplier::query()->firstOrFail();

        Livewire::test(ListCashPurchaseInvoices::class)
            ->callAction('create', data: ['account_id' => Settings::account('cash'), 'cash_supplier_id' => $supplier->id, 'supplier_invoice_no' => 'X-1'])
            ->assertHasNoActionErrors();

        $doc = PurchaseDocument::query()->where('type', 'cash_purchase_invoice')->latest('id')->firstOrFail();
        $this->assertSame(CashPurchaseInvoiceResource::getUrl('edit', ['record' => $doc]), CashPurchaseInvoiceResource::getUrl('edit', ['record' => $doc->id]));

        Livewire::test(EditCashPurchaseInvoice::class, ['record' => $doc->getRouteKey()])
            ->set('data.lines', ['a' => ['item_id' => $item->id, 'quantity' => 10, 'unit_price' => 4, 'tax_percent' => 15, 'discount_percent' => 0, 'discount2_percent' => 0, 'bonus_qty' => 0, 'bonus_percent' => 0]])
            ->set('data.post_now', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $doc->refresh();
        $this->assertTrue($doc->is_posted);
        $lines = $doc->journalEntry->lines()->get();
        $this->assertEquals(46, (float) $lines->firstWhere('account_id', Settings::account('cash'))->credit);
        $this->assertEquals(40, (float) $lines->firstWhere('account_id', Settings::account('inventory'))->debit);
        $this->assertEquals(6, (float) $lines->firstWhere('account_id', Settings::account('vat_input'))->debit);
        $this->assertEquals($before + 10, StockService::balance($item->id));
    }

    public function test_cash_sales_invoice_and_return_post_against_cash(): void
    {
        $this->seedDemo();
        $item = Item::query()->where('code', '1005')->firstOrFail();
        $customer = CashCustomer::query()->firstOrFail();

        Livewire::test(ListCashSalesInvoices::class)
            ->callAction('create', data: ['account_id' => Settings::account('cash'), 'cash_customer_id' => $customer->id, 'manual_no' => 'M-9'])
            ->assertHasNoActionErrors();

        $invoice = SalesDocument::query()->where('type', 'cash_sales_invoice')->latest('id')->firstOrFail();
        $this->assertSame('cash', $invoice->payment_method);

        Livewire::test(EditCashSalesInvoice::class, ['record' => $invoice->getRouteKey()])
            ->set('data.lines', ['a' => ['item_id' => $item->id, 'quantity' => 2, 'unit_price' => 29, 'discount_percent' => 0, 'tax_percent' => 15]])
            ->set('data.post_now', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $invoice->refresh();
        $this->assertTrue($invoice->is_posted);
        $this->assertEquals(66.70, (float) $invoice->grand_total);
        $this->assertEquals(66.70, (float) $invoice->journalEntry->lines()->where('account_id', Settings::account('cash'))->value('debit'));

        Livewire::test(ListCashSalesReturns::class)
            ->callAction('create', data: ['account_id' => Settings::account('cash'), 'original_document_id' => null])
            ->assertHasActionErrors(['original_document_id' => 'required']);

        Livewire::test(ListCashSalesReturns::class)
            ->callAction('create', data: ['account_id' => Settings::account('cash'), 'original_document_id' => $invoice->id])
            ->assertHasNoActionErrors();
        $return = SalesDocument::query()->where('type', 'cash_sales_return')->where('original_document_id', $invoice->id)->firstOrFail();
        $this->assertCount(1, $return->lines);
        $this->assertEquals(66.70, (float) $return->grand_total);

        Livewire::test(ListCashZatcaSalesInvoices::class)
            ->assertCanSeeTableRecords([$invoice])
            ->assertCanNotSeeTableRecords(SalesDocument::query()->where('type', 'cash_sales_invoice')->where('is_posted', false)->get());
    }
}
