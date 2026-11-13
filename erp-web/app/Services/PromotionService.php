<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Promotion;
use App\Models\SalesDocument;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Promotions (العروض) applied to POS lines.
 *
 * Types:
 *  - percent:      line discount % = value
 *  - fixed_price:  unit price (excl. VAT) = value
 *  - buy_x_get_y:  free quantity = floor(quantity / buy_qty) * free_qty
 *
 * An item promotion wins over a category promotion; a branch promotion wins over an all-branches one.
 */
class PromotionService
{
    public const TYPES = [
        'percent' => 'Percent Discount',
        'fixed_price' => 'Fixed Price',
        'buy_x_get_y' => 'Buy X Get Y',
    ];

    public static function activeFor(Item $item, ?int $branchId, CarbonInterface|string|null $date = null): ?Promotion
    {
        $day = Carbon::parse($date ?? now())->toDateString();

        return Promotion::query()
            ->where('is_active', true)
            ->whereDate('from_date', '<=', $day)
            ->whereDate('to_date', '>=', $day)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->where(fn ($q) => $q->where('item_id', $item->id)->orWhere(fn ($q) => $q->whereNull('item_id')->where('category_id', $item->category_id)))
            ->orderByRaw('case when item_id is null then 1 else 0 end')
            ->orderByRaw('case when branch_id is null then 1 else 0 end')
            ->latest('id')
            ->first();
    }

    /**
     * Apply the active promotion to a line array (form state or attributes).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function applyToLine(array $line, ?int $branchId, CarbonInterface|string|null $date = null): array
    {
        $item = filled($line['item_id'] ?? null) ? Item::query()->find($line['item_id']) : null;
        if (! $item) {
            return $line;
        }

        $promotion = static::activeFor($item, $branchId, $date);
        $hadPromotion = filled($line['promotion_id'] ?? null);
        $line['promotion_id'] = $promotion?->id;

        if (! $promotion) {
            if ($hadPromotion) {
                $line['free_qty'] = 0;
            }

            return $line;
        }

        $value = (float) $promotion->value;
        $quantity = (float) ($line['quantity'] ?? 0);

        match ($promotion->promotion_type) {
            'percent' => $line['discount_percent'] = min(100, $value),
            'fixed_price' => $line['unit_price'] = $value,
            'buy_x_get_y' => $line['free_qty'] = (float) $promotion->buy_qty > 0
                ? floor($quantity / (float) $promotion->buy_qty) * (float) $promotion->free_qty
                : 0,
            default => null,
        };

        return $line;
    }

    /** Apply promotions to the stored lines of a draft document. */
    public static function applyToDocument(SalesDocument $doc): void
    {
        if ($doc->is_posted) {
            return;
        }

        foreach ($doc->lines()->get() as $line) {
            $applied = static::applyToLine($line->only(['item_id', 'quantity', 'unit_price', 'discount_percent', 'free_qty', 'promotion_id']), $doc->branch_id, $doc->date);
            $line->fill(array_intersect_key($applied, array_flip(['unit_price', 'discount_percent', 'free_qty', 'promotion_id'])));
            if ($line->isDirty()) {
                $line->save();
            }
        }

        $doc->unsetRelation('lines');
    }
}
