<?php

namespace App\Services\Calc;

/**
 * Pure arithmetic for document lines and totals. Works on plain arrays so it can be used
 * both from Filament form state (live totals) and from services (on save / posting).
 *
 * Sales line:    gross = qty * unit_price; discount = gross * discount% (or fixed); taxable = gross - discount;
 *                tax = taxable * tax%; net = taxable + tax.
 * Purchase line: same, plus a second discount (discount2) applied after the first, and
 *                unit_net = taxable / (qty + bonus_qty).
 * Document:      additional discount (fixed or %) is a document-level allowance that reduces the
 *                taxable base proportionally (ZATCA-compliant), so tax_total is recomputed after it.
 */
class DocumentCalculator
{
    public static function round(float $v, int $p = 2): float
    {
        return round($v + 0.0, $p);
    }

    public static function num(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /** @return array{gross: float, discount: float, discount2: float, taxable: float, tax: float, net: float, unit_net: float, bonus_qty: float} */
    public static function line(array $line): array
    {
        $qty = static::num($line['quantity'] ?? 0);
        $price = static::num($line['unit_price'] ?? $line['price'] ?? 0);
        $gross = static::round($qty * $price);

        $pct = static::num($line['discount_percent'] ?? 0);
        $discount = $pct > 0 ? static::round($gross * $pct / 100) : static::num($line['discount'] ?? 0);
        $discount = min($discount, $gross);

        $pct2 = static::num($line['discount2_percent'] ?? 0);
        $after1 = $gross - $discount;
        $discount2 = $pct2 > 0 ? static::round($after1 * $pct2 / 100) : static::num($line['discount2'] ?? 0);
        $discount2 = min($discount2, $after1);

        $bonusPct = static::num($line['bonus_percent'] ?? 0);
        $bonusQty = $bonusPct > 0 ? round($qty * $bonusPct / 100, 3) : static::num($line['bonus_qty'] ?? 0);

        $taxable = static::round($gross - $discount - $discount2);
        $tax = static::round($taxable * static::num($line['tax_percent'] ?? 0) / 100);
        $net = static::round($taxable + $tax);
        $unitNet = ($qty + $bonusQty) > 0 ? round($taxable / ($qty + $bonusQty), 4) : 0.0;

        return [
            'gross' => $gross,
            'discount' => static::round($discount),
            'discount2' => static::round($discount2),
            'taxable' => $taxable,
            'tax' => $tax,
            'net' => $net,
            'unit_net' => $unitNet,
            'bonus_qty' => $bonusQty,
        ];
    }

    /**
     * @param  array<int, array>  $lines  raw lines (will be recalculated)
     * @return array{lines: array, gross_total: float, items_discount: float, additional_discount: float, net_total: float, tax_total: float, grand_total: float}
     */
    public static function totals(array $lines, mixed $additionalDiscount = 0, mixed $additionalDiscountPercent = 0): array
    {
        $calculated = [];
        $gross = $itemsDiscount = $taxable = 0.0;

        foreach ($lines as $key => $line) {
            $c = static::line($line);
            $calculated[$key] = array_merge($line, $c);
            $gross += $c['gross'];
            $itemsDiscount += $c['discount'] + $c['discount2'];
            $taxable += $c['taxable'];
        }

        $pct = static::num($additionalDiscountPercent);
        $addDiscount = $pct > 0 ? static::round($taxable * $pct / 100) : static::num($additionalDiscount);
        $addDiscount = min($addDiscount, $taxable);
        $factor = $taxable > 0 ? ($taxable - $addDiscount) / $taxable : 0;

        $tax = 0.0;
        foreach ($calculated as $c) {
            $tax += static::round($c['taxable'] * $factor * static::num($c['tax_percent'] ?? 0) / 100);
        }

        $net = static::round($taxable - $addDiscount);

        return [
            'lines' => $calculated,
            'gross_total' => static::round($gross),
            'items_discount' => static::round($itemsDiscount),
            'additional_discount' => static::round($addDiscount),
            'net_total' => $net,
            'tax_total' => static::round($tax),
            'grand_total' => static::round($net + $tax),
        ];
    }
}
