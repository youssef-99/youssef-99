<?php

namespace Tests\Feature\Pos;

use App\Exceptions\ErpException;
use App\Filament\Resources\Pos\PosSalesInvoiceResource\Pages\CreatePosSalesInvoice;
use App\Models\Motivation;
use App\Models\PointsCustomer;
use App\Models\PointsTransaction;
use App\Models\Promotion;
use App\Services\LoyaltyPointsService;
use App\Services\MotivationService;
use App\Services\PosService;
use App\Services\Settings;
use Livewire\Livewire;
use Tests\ErpTestCase;

class PromotionAndLoyaltyTest extends ErpTestCase
{
    use PosTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
    }

    protected function promotion(array $attributes): Promotion
    {
        return Promotion::query()->create(array_merge([
            'code' => (string) (Promotion::query()->count() + 100),
            'name_ar' => 'عرض',
            'from_date' => now()->subDay(),
            'to_date' => now()->addDay(),
        ], $attributes));
    }

    public function test_percent_promotion_is_applied_when_scanning(): void
    {
        $item = $this->item('1005');
        $promotion = $this->promotion(['item_id' => $item->id, 'promotion_type' => 'percent', 'value' => 10]);

        $component = Livewire::test(CreatePosSalesInvoice::class)->set('data.scan', $item->barcode);
        $line = array_values($component->get('data.lines'))[0];

        $this->assertEquals($promotion->id, $line['promotion_id']);
        $this->assertEquals(10, (float) $line['discount_percent']);
        $this->assertEquals(round(29 * 0.9 * 1.15, 2), (float) $component->get('data.grand_total'));
    }

    public function test_promotions_are_applied_on_recalculation_and_expired_ones_ignored(): void
    {
        $masks = $this->item('1006');
        $cream = $this->item('1005');
        $this->promotion(['category_id' => $masks->category_id, 'promotion_type' => 'buy_x_get_y', 'buy_qty' => 2, 'free_qty' => 1]);
        $this->promotion(['item_id' => $cream->id, 'promotion_type' => 'fixed_price', 'value' => 20, 'from_date' => now()->subMonth(), 'to_date' => now()->subWeek()]);

        $doc = PosService::recalculate($this->draftPos(['1006' => 5, '1005' => 1]));
        $maskLine = $doc->lines()->where('item_id', $masks->id)->first();
        $creamLine = $doc->lines()->where('item_id', $cream->id)->first();

        $this->assertEquals(2, (float) $maskLine->free_qty);
        $this->assertNotNull($maskLine->promotion_id);
        $this->assertEquals(29, (float) $creamLine->unit_price);
        $this->assertNull($creamLine->promotion_id);
    }

    public function test_points_are_earned_redeemed_and_reversed(): void
    {
        Settings::set(LoyaltyPointsService::POINT_VALUE_SETTING, '0.5');
        $customer = PointsCustomer::query()->firstOrFail();
        $customer->update(['points_balance' => 100]);
        PointsTransaction::query()->delete();

        // 1008 has 0% VAT and the Medicines category (1 point per 100 SAR); 10 × 21.00 = 210.00
        $doc = $this->draftPos(['1008' => 10], ['points_customer_id' => $customer->id, 'points_redeemed' => 20, 'additional_discount' => 10, 'cash_amount' => 300]);
        $doc = PosService::post($doc);

        $this->assertEquals(10.00, (float) $doc->points_discount);
        $this->assertEquals(200.00, (float) $doc->grand_total);
        $this->assertEquals(-20, (float) PointsTransaction::query()->where('sales_document_id', $doc->id)->where('points', '<', 0)->sum('points'));
        $earned = (float) $doc->points_earned;
        $this->assertGreaterThan(0, $earned);
        $this->assertEquals(round(100 - 20 + $earned, 2), (float) $customer->refresh()->points_balance);

        PosService::unpost($doc);

        $this->assertEquals(100, (float) $customer->refresh()->points_balance);
        $this->assertSame(0, PointsTransaction::query()->where('sales_document_id', $doc->id)->count());
    }

    public function test_redeeming_more_points_than_the_balance_is_rejected(): void
    {
        $customer = PointsCustomer::query()->firstOrFail();
        $customer->update(['points_balance' => 5]);
        $doc = $this->draftPos(['1008' => 1], ['points_customer_id' => $customer->id, 'points_redeemed' => 50, 'cash_amount' => 100]);

        $this->expectException(ErpException::class);
        PosService::post($doc);
    }

    public function test_pos_return_reverses_points(): void
    {
        $customer = PointsCustomer::query()->firstOrFail();
        $invoice = PosService::post($this->draftPos(['1008' => 10], ['points_customer_id' => $customer->id, 'cash_amount' => 300]));
        $balance = (float) $customer->refresh()->points_balance;

        $return = PosService::post($this->draftPos(['1008' => 10], ['original_document_id' => $invoice->id], 'pos_return'));

        $this->assertLessThan(0, (float) $return->points_earned);
        $this->assertEquals(round($balance + (float) $return->points_earned, 2), (float) $customer->refresh()->points_balance);
    }

    public function test_redeem_field_converts_points_to_additional_discount(): void
    {
        Settings::set(LoyaltyPointsService::POINT_VALUE_SETTING, '0.1');
        $customer = PointsCustomer::query()->firstOrFail();

        $component = Livewire::test(CreatePosSalesInvoice::class)
            ->set('data.scan', $this->item('1008')->code)
            ->set('data.points_customer_id', $customer->id)
            ->set('data.points_redeemed', 30);

        $this->assertEquals(3.0, (float) $component->get('data.additional_discount'));
        $this->assertEquals(18.0, (float) $component->get('data.grand_total'));
    }

    public function test_staff_incentives_are_computed_per_selling_user(): void
    {
        $item = $this->item('1005');
        Motivation::query()->create(['code' => 'T1', 'name_ar' => 'حافز', 'item_id' => $item->id, 'motivation_type' => 'per_unit', 'value' => 2, 'from_date' => now()->subDay(), 'to_date' => now()->addDay()]);
        Motivation::query()->create(['code' => 'T2', 'name_ar' => 'حافز فئة', 'category_id' => $this->item('1006')->category_id, 'motivation_type' => 'percent', 'value' => 10, 'from_date' => now()->subDay(), 'to_date' => now()->addDay()]);

        $baseline = (float) (MotivationService::earned(now()->startOfDay(), now(), userId: $this->admin->id)->first()['incentive'] ?? 0);
        $invoice = PosService::post($this->draftPos(['1005' => 3, '1006' => 2], ['cash_amount' => 500]));
        PosService::post($this->draftPos(['1005' => 1], ['original_document_id' => $invoice->id], 'pos_return'));

        $row = MotivationService::earned(now()->startOfDay(), now(), userId: $this->admin->id)->first();

        $this->assertSame($this->admin->id, $row['user_id']);
        $this->assertEquals(round($baseline + 3 * 2 + 2 * 9 * 0.10 - 1 * 2, 2), $row['incentive']);
    }
}
