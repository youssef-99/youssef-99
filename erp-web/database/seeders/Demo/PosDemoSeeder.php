<?php

namespace Database\Seeders\Demo;

use App\Models\DrugDose;
use App\Models\Item;
use App\Models\Motivation;
use App\Models\Promotion;
use App\Models\SalesDocument;
use App\Services\PosService;
use App\Services\Zatca\ZatcaNoteService;
use Illuminate\Database\Seeder;

/**
 * POS demo data: drug doses, a promotion, a staff incentive, a POS return and a ZATCA credit note.
 */
class PosDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Promotion::query()->exists()) {
            return;
        }

        $panadol = Item::query()->where('code', '1001')->first();
        $cream = Item::query()->where('code', '1005')->first();
        $masks = Item::query()->where('code', '1006')->first();

        DrugDose::query()->create(['code' => '1', 'name_ar' => 'قرص واحد كل 8 ساعات', 'name_en' => 'One tablet every 8 hours', 'frequency' => '3x', 'item_id' => $panadol?->id]);
        DrugDose::query()->create(['code' => '2', 'name_ar' => 'ملعقة صغيرة مرتين يومياً', 'name_en' => 'One teaspoon twice daily', 'frequency' => '2x']);

        if ($masks) {
            Promotion::query()->create([
                'code' => '1', 'name_ar' => 'اشتر 2 واحصل على 1 مجاناً', 'name_en' => 'Buy 2 get 1 free', 'item_id' => $masks->id,
                'promotion_type' => 'buy_x_get_y', 'buy_qty' => 2, 'free_qty' => 1, 'from_date' => now()->startOfMonth(), 'to_date' => now()->endOfMonth(),
            ]);
        }

        if ($cream) {
            Motivation::query()->create([
                'code' => '1', 'name_ar' => 'حافز الكريمات', 'name_en' => 'Cream incentive', 'category_id' => $cream->category_id,
                'motivation_type' => 'percent', 'value' => 2, 'from_date' => now()->startOfMonth(), 'to_date' => now()->endOfMonth(),
            ]);
        }

        $invoice = SalesDocument::query()->where('type', 'pos_invoice')->where('is_posted', true)->oldest('id')->with('lines')->first();
        if ($invoice && ($line = $invoice->lines->first())) {
            $return = SalesDocument::query()->create([
                'type' => 'pos_return', 'branch_id' => $invoice->branch_id, 'warehouse_id' => $invoice->warehouse_id, 'date' => now(),
                'time' => '18:00:00', 'original_document_id' => $invoice->id, 'notes' => 'مرتجع تجريبي',
            ]);
            $return->lines()->create($line->only(['item_id', 'unit_id', 'item_batch_id', 'unit_price', 'tax_percent']) + ['quantity' => 1]);
            PosService::post($return);
        }

        $discounted = SalesDocument::query()->where('type', 'pos_invoice')->where('is_posted', true)->latest('id')->first();
        if ($discounted) {
            ZatcaNoteService::createCustomerDiscount($discounted, 5, 'خصم للعميل', now());
        }
    }
}
