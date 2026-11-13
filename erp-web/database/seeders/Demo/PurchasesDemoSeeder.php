<?php

namespace Database\Seeders\Demo;

use App\Models\Account;
use App\Models\Accreditation;
use App\Models\AccreditationFee;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\City;
use App\Models\Currency;
use App\Models\Item;
use App\Models\PurchaseDocument;
use App\Models\Supplier;
use App\Models\SupplierGroup;
use App\Services\AccreditationService;
use App\Services\DocumentNumber;
use App\Services\PurchaseDocumentService;
use App\Services\Settings;
use App\Services\SupplierService;
use Illuminate\Database\Seeder;

/**
 * Purchases demo data: a foreign supplier, a purchase order, a posted purchase return,
 * a letter of credit with a posted fee and a draft foreign purchase invoice.
 */
class PurchasesDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (PurchaseDocument::query()->where('type', 'purchase_order')->exists()) {
            return;
        }

        $branch = Branch::query()->firstOrFail();
        $local = Supplier::query()->firstOrFail();
        $invoice = PurchaseDocument::query()->where('type', 'purchase_invoice')->where('is_posted', true)->first();
        $usd = Currency::query()->where('iso_code', 'USD')->first();
        $city = City::query()->first();
        $items = Item::query()->orderBy('code')->get()->keyBy('code');

        $foreign = Supplier::query()->create([
            'code' => DocumentNumber::nextCode(Supplier::class), 'name_ar' => 'شركة فايزر العالمية', 'name_en' => 'Pfizer International',
            'supplier_type' => 'foreign', 'supplier_group_id' => SupplierGroup::query()->value('id'), 'currency_id' => $usd?->id,
            'country_id' => $city?->country_id, 'city_id' => $city?->id, 'payment_term_days' => 90, 'opening_credit' => 15000,
        ]);
        SupplierService::afterSave($foreign);

        $order = PurchaseDocument::query()->create([
            'type' => 'purchase_order', 'branch_id' => $branch->id, 'date' => now()->subDays(2),
            'supplier_id' => $local->id, 'account_id' => $local->account_id, 'notes' => 'طلب شهري',
        ]);
        foreach (['1001' => 50, '1003' => 30] as $code => $qty) {
            $item = $items[$code];
            $order->lines()->create(['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => $qty, 'unit_price' => $item->purchase_price, 'tax_percent' => $item->vat_rate]);
        }
        PurchaseDocumentService::recalculate($order);

        if ($invoice) {
            $line = $invoice->lines()->where('item_id', $items['1005']->id)->first();
            $return = PurchaseDocument::query()->create([
                'type' => 'purchase_return', 'branch_id' => $branch->id, 'date' => now()->subDay(), 'warehouse_id' => $invoice->warehouse_id,
                'supplier_id' => $local->id, 'account_id' => $local->account_id, 'reference_type' => 'invoice', 'original_document_id' => $invoice->id,
            ]);
            $return->lines()->create(['item_id' => $line->item_id, 'unit_id' => $line->unit_id, 'quantity' => 2, 'unit_price' => $line->unit_price, 'tax_percent' => $line->tax_percent]);
            PurchaseDocumentService::post($return);
        }

        $bank = Bank::query()->first() ?? Bank::query()->create([
            'code' => DocumentNumber::nextCode(Bank::class), 'name_ar' => 'مصرف الراجحي', 'name_en' => 'Al Rajhi Bank',
            'account_id' => Account::query()->where('number', '110103')->value('id'),
        ]);

        $lc = Accreditation::query()->create([
            'number' => 'LC-'.now()->format('Y').'-001', 'bank_id' => $bank->id,
            'lc_account_id' => Account::query()->where('type', 'lc')->where('is_postable', true)->value('id'),
            'inventory_account_id' => Settings::account('inventory'), 'supplier_id' => $foreign->id,
            'country_id' => $city?->country_id, 'currency_id' => $usd?->id, 'value' => 20000, 'bank_charges' => 750,
            'open_date' => now()->subDays(20), 'status' => 'open',
        ]);

        $fee = AccreditationFee::query()->create([
            'accreditation_id' => $lc->id, 'branch_id' => $branch->id, 'number' => '1', 'date' => now()->subDays(19),
            'fee_type' => 'bank_charges', 'amount' => 750, 'credit_account_id' => $bank->account_id ?? Account::query()->where('number', '110103')->value('id'),
            'description' => 'عمولة فتح الاعتماد',
        ]);
        AccreditationService::post($fee);

        $foreignInvoice = PurchaseDocument::query()->create([
            'type' => 'foreign_purchase_invoice', 'branch_id' => $branch->id, 'date' => now()->subDays(5), 'receipt_date' => now()->subDays(4),
            'supplier_id' => $foreign->id, 'account_id' => $foreign->account_id, 'supplier_invoice_no' => 'PF-9001',
            'currency_id' => $usd?->id, 'exchange_rate' => 3.75, 'accreditation_id' => $lc->id, 'shipping_cost' => 300, 'purchase_expenses' => 200,
            'payment_period_days' => 90,
        ]);
        $item = $items['1004'];
        $foreignInvoice->lines()->create([
            'item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 200, 'unit_price' => 8, 'tax_percent' => 15,
            'batch_no' => 'PF2401', 'expiry_date' => now()->addYears(2)->startOfMonth(),
        ]);
        PurchaseDocumentService::recalculate($foreignInvoice);
    }
}
