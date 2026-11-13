<?php

namespace Database\Seeders\Demo;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\District;
use App\Models\Item;
use App\Models\MarketingRep;
use App\Models\Region;
use App\Models\SalesDocument;
use App\Models\SalesPolicy;
use App\Services\CustomerOrderService;
use App\Services\SalesDocumentService;
use Illuminate\Database\Seeder;

/**
 * Sales demo data: marketing rep, activity, sales policy, a confirmed quotation, a posted customer order
 * and a posted sales return against the demo invoice. Idempotent.
 */
class SalesDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (SalesDocument::query()->where('type', 'quotation')->exists()) {
            return;
        }

        $customer = Customer::query()->orderBy('id')->first();
        $invoice = SalesDocument::query()->where('type', 'sales_invoice')->where('is_posted', true)->orderBy('id')->first();
        if (! $customer || ! $invoice) {
            return;
        }

        $rep = MarketingRep::query()->firstOrCreate(['code' => '1'], [
            'name_ar' => 'سامي حسن', 'name_en' => 'Sami Hassan', 'branch_id' => $invoice->branch_id, 'mobile' => '0500000002', 'commission_percent' => 1,
        ]);
        $activity = Activity::query()->orderBy('id')->first()
            ?? Activity::query()->create(['code' => '1', 'name_ar' => 'مستوصفات', 'name_en' => 'Clinics']);
        $policy = SalesPolicy::query()->firstOrCreate(['code' => '1'], [
            'name_ar' => 'خصم الجملة 5%', 'name_en' => 'Wholesale 5% discount', 'discount_percent' => 5,
        ]);

        $customer->forceFill([
            'marketing_rep_id' => $customer->marketing_rep_id ?? $rep->id,
            'activity_id' => $customer->activity_id ?? $activity->id,
            'district_id' => $customer->district_id ?? District::query()->where('city_id', $customer->city_id)->value('id'),
            'region_id' => $customer->region_id ?? Region::query()->value('id'),
            'sales_policy_id' => $customer->sales_policy_id ?? $policy->id,
        ])->save();

        $items = Item::query()->whereIn('code', ['1002', '1004'])->get()->keyBy('code');
        $header = [
            'branch_id' => $invoice->branch_id, 'warehouse_id' => $invoice->warehouse_id, 'date' => now()->subDay(),
            'customer_id' => $customer->id, 'account_id' => $customer->account_id, 'salesman_id' => $customer->salesman_id,
        ];

        $quotation = SalesDocument::query()->create(['type' => 'quotation', 'due_date' => now()->addDays(14)] + $header);
        $order = SalesDocument::query()->create(['type' => 'customer_order'] + $header);
        foreach ([$quotation, $order] as $doc) {
            foreach (['1002' => 20, '1004' => 4] as $code => $qty) {
                if ($item = $items->get($code)) {
                    $doc->lines()->create([
                        'item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => $qty,
                        'unit_price' => $item->sale_price, 'tax_percent' => $item->vat_rate, 'discount_percent' => 5,
                    ]);
                }
            }
            SalesDocumentService::recalculate($doc);
            SalesDocumentService::post($doc);
        }

        $second = SalesDocument::query()->create(['type' => 'customer_order'] + $header);
        if ($item = $items->get('1002')) {
            $line = $second->lines()->create(['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 10, 'unit_price' => $item->sale_price, 'tax_percent' => $item->vat_rate]);
            SalesDocumentService::recalculate($second);
            SalesDocumentService::post($second);
            CustomerOrderService::approve($second, [$line->id => 6]);
        }

        $return = SalesDocument::query()->create(['type' => 'sales_return', 'original_document_id' => $invoice->id, 'date' => now()] + $header);
        $original = $invoice->lines()->orderBy('id')->first();
        $return->lines()->create($original->only(['item_id', 'unit_id', 'item_batch_id', 'unit_price', 'discount_percent', 'tax_percent']) + ['quantity' => 2]);
        SalesDocumentService::recalculate($return);
        SalesDocumentService::post($return);
    }
}
