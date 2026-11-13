<?php

namespace Database\Seeders;

use App\Models\ActiveIngredient;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Doctor;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Manufacturer;
use App\Models\PointsCustomer;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\Salesman;
use App\Models\Supplier;
use App\Models\SupplierGroup;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PartyAccountService;
use App\Services\PurchaseDocumentService;
use App\Services\SalesDocumentService;
use Illuminate\Database\Seeder;

/**
 * Demo master data and a few posted documents so lists, reports and the dashboard have content.
 */
class DemoSeeder extends Seeder
{
    /** Per-module demo data, owned by each module (run after the core demo data, while logged in as admin). */
    public const MODULE_SEEDERS = [
        'Database\\Seeders\\Demo\\GeneralDemoSeeder',
        'Database\\Seeders\\Demo\\AccountingDemoSeeder',
        'Database\\Seeders\\Demo\\InventoryDemoSeeder',
        'Database\\Seeders\\Demo\\SalesDemoSeeder',
        'Database\\Seeders\\Demo\\PosDemoSeeder',
        'Database\\Seeders\\Demo\\PurchasesDemoSeeder',
        'Database\\Seeders\\Demo\\CashDemoSeeder',
    ];

    public function run(): void
    {
        if (Item::query()->exists()) {
            return;
        }

        auth()->loginUsingId(User::query()->where('username', 'admin')->value('id'));

        $branch = Branch::query()->first();
        $warehouse = Warehouse::query()->first();
        $city = City::query()->first();
        $box = Unit::query()->where('code', '1')->first();

        $categories = [];
        foreach ([['1', 'أدوية', 'Medicines', 1], ['2', 'مستحضرات تجميل', 'Cosmetics', 3], ['3', 'مكملات غذائية', 'Supplements', 2], ['4', 'مستلزمات طبية', 'Medical Supplies', 1]] as [$c, $ar, $en, $pts]) {
            $categories[$c] = ItemCategory::query()->create(['code' => $c, 'name_ar' => $ar, 'name_en' => $en, 'points_percent' => $pts, 'is_sales_category' => true]);
        }

        $agent = Agent::query()->create(['code' => '1', 'name_ar' => 'وكيل الشرق الأوسط', 'name_en' => 'Middle East Agent']);
        $manufacturer = Manufacturer::query()->create(['code' => '1', 'name_ar' => 'جلفار', 'name_en' => 'Julphar']);
        $paracetamol = ActiveIngredient::query()->create(['code' => '1', 'name_ar' => 'باراسيتامول', 'name_en' => 'Paracetamol']);

        $sg = SupplierGroup::query()->create(['code' => '1', 'name_ar' => 'موردو الأدوية', 'name_en' => 'Drug Suppliers']);
        $supplier = Supplier::query()->create([
            'code' => '1', 'name_ar' => 'شركة نهدي للأدوية', 'name_en' => 'Nahdi Pharma Co.', 'supplier_group_id' => $sg->id,
            'vat_no' => '300000000000013', 'country_id' => $city->country_id, 'city_id' => $city->id, 'payment_term_days' => 60,
        ]);
        PartyAccountService::sync($supplier);

        $items = [
            ['1001', '6281001000011', 'بنادول 500 مجم', 'Panadol 500mg', '1', 'batch_expiry', 15, 8.50, 12.00],
            ['1002', '6281001000028', 'بروفين 400 مجم', 'Brufen 400mg', '1', 'batch_expiry', 15, 10.00, 15.50],
            ['1003', '6281001000035', 'فيتامين سي 1000', 'Vitamin C 1000', '3', 'batch_expiry', 15, 20.00, 32.00],
            ['1004', '6281001000042', 'أوميغا 3', 'Omega 3', '3', 'batch_expiry', 15, 35.00, 55.00],
            ['1005', '6281001000059', 'كريم مرطب', 'Moisturizing Cream', '2', 'standard', 15, 18.00, 29.00],
            ['1006', '6281001000066', 'كمامات طبية', 'Face Masks', '4', 'standard', 15, 5.00, 9.00],
            ['1007', '6281001000073', 'جهاز قياس ضغط', 'Blood Pressure Monitor', '4', 'serial', 15, 120.00, 185.00],
            ['1008', '6281001000080', 'أموكسيسيلين 500', 'Amoxicillin 500', '1', 'batch_expiry', 0, 14.00, 21.00],
        ];
        $models = [];
        foreach ($items as [$code, $barcode, $ar, $en, $cat, $type, $vat, $cost, $price]) {
            $models[$code] = Item::query()->create([
                'code' => $code, 'barcode' => $barcode, 'gtin' => '0'.$barcode, 'name_ar' => $ar, 'name_en' => $en,
                'category_id' => $categories[$cat]->id, 'agent_id' => $agent->id, 'supplier_id' => $supplier->id,
                'manufacturer_id' => $manufacturer->id, 'unit_id' => $box?->id, 'item_type' => $type, 'item_nature' => 'stock',
                'vat_rate' => $vat, 'tax_exemption_reason' => $vat == 0 ? 'VATEX-SA-HEA' : null, 'purchase_price' => $cost, 'sale_price' => $price,
            ]);
        }
        $models['1001']->activeIngredients()->attach($paracetamol->id, ['strength' => '500mg']);

        $cg = CustomerGroup::query()->create(['code' => '1', 'name_ar' => 'عملاء الجملة', 'name_en' => 'Wholesale Customers']);
        $salesman = Salesman::query()->create(['code' => '1', 'name_ar' => 'أحمد علي', 'name_en' => 'Ahmed Ali', 'branch_id' => $branch->id, 'mobile' => '0500000001', 'commission_percent' => 2]);
        $customer = Customer::query()->create([
            'code' => '1', 'name_ar' => 'مستوصف الشفاء', 'name_en' => 'Al Shifa Clinic', 'customer_group_id' => $cg->id, 'salesman_id' => $salesman->id,
            'country_id' => $city->country_id, 'city_id' => $city->id, 'vat_no' => '300000000000023', 'customer_type' => 'company',
            'credit_limit' => 50000, 'payment_term_days' => 30, 'street_name' => 'شارع العليا', 'building_no' => '2222', 'postal_code' => '12212',
        ]);
        PartyAccountService::sync($customer);

        Doctor::query()->create(['code' => '1', 'name_ar' => 'د. سارة محمد', 'name_en' => 'Dr. Sara Mohammed', 'specialty' => 'باطنية']);
        $points = PointsCustomer::query()->create(['code' => '1', 'name' => 'خالد عبدالله', 'mobile' => '0555555555', 'delivery_code' => 'D-100']);

        // Purchase invoice to bring stock in
        $purchase = PurchaseDocument::query()->create([
            'type' => 'purchase_invoice', 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'date' => now()->subDays(10),
            'supplier_id' => $supplier->id, 'account_id' => $supplier->account_id, 'supplier_invoice_no' => 'INV-5001',
            'receipt_date' => now()->subDays(10), 'payment_period_days' => 60,
        ]);
        foreach ($models as $code => $item) {
            $purchase->lines()->create([
                'item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 100, 'unit_price' => $item->purchase_price,
                'tax_percent' => $item->vat_rate, 'batch_no' => $item->isBatchTracked() ? 'B'.$code : null,
                'expiry_date' => $item->isBatchTracked() ? now()->addMonths(18)->startOfMonth() : null,
                'discount_percent' => (string) $code === '1001' ? 5 : 0, 'bonus_qty' => (string) $code === '1001' ? 10 : 0,
            ]);
        }
        PurchaseDocumentService::post($purchase);

        // Credit sales invoice
        $invoice = SalesDocument::query()->create([
            'type' => 'sales_invoice', 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'date' => now()->subDays(3),
            'due_date' => now()->addDays(27), 'customer_id' => $customer->id, 'account_id' => $customer->account_id, 'salesman_id' => $salesman->id,
        ]);
        foreach (['1002' => 10, '1003' => 5, '1006' => 50] as $code => $qty) {
            $item = $models[$code];
            $invoice->lines()->create([
                'item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => $qty, 'unit_price' => $item->sale_price, 'tax_percent' => $item->vat_rate,
                'item_batch_id' => $item->batches()->value('id'),
            ]);
        }
        SalesDocumentService::post($invoice);

        // POS invoices over the last week
        foreach (range(0, 6) as $daysAgo) {
            $pos = SalesDocument::query()->create([
                'type' => 'pos_invoice', 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'date' => now()->subDays($daysAgo),
                'time' => '10:'.str_pad((string) ($daysAgo * 7), 2, '0', STR_PAD_LEFT).':00', 'payment_method' => 'cash',
                'points_customer_id' => $daysAgo % 2 ? $points->id : null,
            ]);
            foreach (['1001' => 2 + $daysAgo, '1005' => 1, '1008' => 1] as $code => $qty) {
                $item = $models[$code];
                $pos->lines()->create([
                    'item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => $qty, 'unit_price' => $item->sale_price, 'tax_percent' => $item->vat_rate,
                    'item_batch_id' => $item->batches()->value('id'),
                ]);
            }
            SalesDocumentService::recalculate($pos);
            $pos->update(['cash_amount' => $pos->grand_total, 'amount_received' => ceil((float) $pos->grand_total)]);
            SalesDocumentService::post($pos);
        }

        foreach (self::MODULE_SEEDERS as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }

        auth()->logout();
    }
}
