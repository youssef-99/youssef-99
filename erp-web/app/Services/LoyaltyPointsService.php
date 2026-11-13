<?php

namespace App\Services;

use App\Enums\SalesDocumentType;
use App\Exceptions\ErpException;
use App\Models\PointsCustomer;
use App\Models\PointsTransaction;
use App\Models\SalesDocument;

/**
 * Loyalty points (نقاط الولاء).
 *
 *  - Earn: on a posted POS invoice, points = Σ line taxable net (after discounts) × item category "Category Points %".
 *  - Redeem: points_redeemed × point value (setting `pos.point_value`, SAR per point) is given as an additional discount.
 *  - Reverse: on unpost every points transaction of the document is removed; a POS return earns negative points.
 */
class LoyaltyPointsService
{
    public const POINT_VALUE_SETTING = 'pos.point_value';

    public static function pointValue(): float
    {
        return (float) Settings::get(self::POINT_VALUE_SETTING, 0.1);
    }

    public static function discountFor(float $points): float
    {
        return round(max(0, $points) * static::pointValue(), 2);
    }

    /** Points the document earns (negative for a return). */
    public static function pointsFor(SalesDocument $doc): float
    {
        $doc->loadMissing('lines.item.category');
        $taxable = $doc->lines->sum(fn ($l) => (float) $l->gross - (float) $l->discount);
        $factor = $taxable > 0 ? ((float) $doc->net_total) / $taxable : 0;

        $points = 0.0;
        foreach ($doc->lines as $line) {
            $lineNet = ((float) $line->gross - (float) $line->discount) * $factor;
            $points += $lineNet * (float) ($line->item?->category?->points_percent ?? 0) / 100;
        }

        $type = SalesDocumentType::tryFrom($doc->type);

        return round($type?->isReturn() ? -$points : $points, 2);
    }

    /** Record earned points once per document (skipped when the posting engine already did it). */
    public static function earn(SalesDocument $doc): float
    {
        if (! $doc->points_customer_id || (float) $doc->points_earned != 0.0) {
            return (float) $doc->points_earned;
        }

        $points = static::pointsFor($doc);
        if ($points == 0.0) {
            return 0.0;
        }

        static::record($doc, $points, __('Points earned').' #'.$doc->number);
        $doc->forceFill(['points_earned' => $points])->saveQuietly();

        return $points;
    }

    /** Check the redemption against the customer balance. */
    public static function assertCanRedeem(SalesDocument $doc): void
    {
        $points = (float) $doc->points_redeemed;
        if ($points <= 0) {
            return;
        }

        $customer = $doc->pointsCustomer ?? throw new ErpException(__('Select a points customer to redeem points.'));
        if ($points > (float) $customer->points_balance + 0.001) {
            throw new ErpException(__('The points customer has only :balance points.', ['balance' => number_format((float) $customer->points_balance, 2)]));
        }
    }

    public static function redeem(SalesDocument $doc): void
    {
        $points = (float) $doc->points_redeemed;
        if ($points <= 0 || ! $doc->points_customer_id) {
            return;
        }

        static::assertCanRedeem($doc);
        static::record($doc, -$points, __('Points redeemed').' #'.$doc->number);
    }

    /** Remove every points transaction of the document and restore the balances. */
    public static function reverse(SalesDocument $doc): void
    {
        PointsTransaction::query()->where('sales_document_id', $doc->id)->get()->each(function (PointsTransaction $t) {
            $t->pointsCustomer?->decrement('points_balance', (float) $t->points);
            $t->delete();
        });

        $doc->forceFill(['points_earned' => 0])->saveQuietly();
    }

    protected static function record(SalesDocument $doc, float $points, string $description): void
    {
        PointsTransaction::query()->create([
            'points_customer_id' => $doc->points_customer_id,
            'sales_document_id' => $doc->id,
            'date' => $doc->date,
            'points' => $points,
            'description' => $description,
        ]);

        PointsCustomer::query()->whereKey($doc->points_customer_id)->increment('points_balance', $points);
    }
}
