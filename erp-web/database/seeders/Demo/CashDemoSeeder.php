<?php

namespace Database\Seeders\Demo;

use App\Models\Branch;
use App\Models\CashCustomer;
use App\Models\CashSupplier;
use App\Models\City;
use App\Models\Item;
use App\Models\PurchaseDocument;
use App\Models\Region;
use App\Models\SalesDocument;
use App\Services\PurchaseDocumentService;
use App\Services\SalesDocumentService;
use App\Services\Settings;
use Illuminate\Database\Seeder;

/**
 * Cash Transactions demo data: a cash supplier and customer, posted cash purchase / sales invoices
 * and draft returns.
 */
class CashDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (CashSupplier::query()->exists()) {
            return;
        }

        $branch = Branch::query()->firstOrFail();
        $city = City::query()->first();
        $cash = Settings::account('cash');
        $item = Item::query()->where('code', '1006')->first() ?? Item::query()->firstOrFail();

        $supplier = CashSupplier::query()->create([
            'code' => '1', 'name_ar' => 'مؤسسة الصحة للتجارة', 'name_en' => 'Al Sehha Trading', 'vat_no' => '310000000000003',
            'country_id' => $city?->country_id, 'city_id' => $city?->id, 'mobile' => '0500000010',
        ]);
        $customer = CashCustomer::query()->create([
            'code' => '1', 'name_ar' => 'محمد سالم', 'name_en' => 'Mohammed Salem', 'country_id' => $city?->country_id,
            'city_id' => $city?->id, 'region_id' => Region::query()->value('id'), 'mobile' => '0500000020',
        ]);

        $purchase = PurchaseDocument::query()->create([
            'type' => 'cash_purchase_invoice', 'branch_id' => $branch->id, 'date' => now()->subDays(4),
            'account_id' => $cash, 'cash_supplier_id' => $supplier->id, 'supplier_invoice_no' => 'C-100',
        ]);
        $purchase->lines()->create(['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 20, 'unit_price' => $item->purchase_price, 'tax_percent' => $item->vat_rate]);
        PurchaseDocumentService::post($purchase);

        $purchaseReturn = PurchaseDocument::query()->create([
            'type' => 'cash_purchase_return', 'branch_id' => $branch->id, 'date' => now()->subDays(3),
            'account_id' => $cash, 'cash_supplier_id' => $supplier->id, 'reference_type' => 'invoice', 'original_document_id' => $purchase->id,
        ]);
        $purchaseReturn->lines()->create(['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 1, 'unit_price' => $item->purchase_price, 'tax_percent' => $item->vat_rate]);
        PurchaseDocumentService::recalculate($purchaseReturn);

        $sale = SalesDocument::query()->create([
            'type' => 'cash_sales_invoice', 'branch_id' => $branch->id, 'date' => now()->subDays(2), 'payment_method' => 'cash',
            'account_id' => $cash, 'cash_customer_id' => $customer->id, 'manual_no' => 'M-1',
        ]);
        $sale->lines()->create(['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 5, 'unit_price' => $item->sale_price, 'tax_percent' => $item->vat_rate]);
        SalesDocumentService::post($sale);

        $saleReturn = SalesDocument::query()->create([
            'type' => 'cash_sales_return', 'branch_id' => $branch->id, 'date' => now()->subDay(), 'payment_method' => 'cash',
            'account_id' => $cash, 'cash_customer_id' => $customer->id, 'original_document_id' => $sale->id,
        ]);
        $saleReturn->lines()->create(['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 1, 'unit_price' => $item->sale_price, 'tax_percent' => $item->vat_rate]);
        SalesDocumentService::recalculate($saleReturn);
    }
}
