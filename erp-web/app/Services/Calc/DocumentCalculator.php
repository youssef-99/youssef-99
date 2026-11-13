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
 *                taxable base proportionally (ZATCA-compliant); tax_total is computed per VAT rate
 *                after it (see vatGroups()), exactly like the ZATCA XML / QR amounts.
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
     * VAT per rate, the way the ZATCA invoice XML computes it (UblInvoiceBuilder::breakdown()): the
     * additional discount is split over the rate groups in proportion to their line totals (rounded,
     * the last group taking the remainder), then tax = round(group taxable x rate / 100, 2).
     * Per-line tax stays round(line taxable x rate / 100, 2) for display only.
     *
     * @param  array<array-key, array>  $calculatedLines  lines with 'taxable' and 'tax_percent'
     * @return array<string, array{rate: float, line_total: float, allowance: float, taxable: float, tax: float}> keyed by rate
     */
    public static function vatGroups(array $calculatedLines, float $additionalDiscount = 0): array
    {
        $groups = [];
        foreach ($calculatedLines as $line) {
            $rate = static::num($line['tax_percent'] ?? 0);
            $key = (string) round($rate, 4);
            $groups[$key] ??= ['rate' => $rate, 'line_total' => 0.0, 'allowance' => 0.0, 'taxable' => 0.0, 'tax' => 0.0];
            $groups[$key]['line_total'] = static::round($groups[$key]['line_total'] + static::num($line['taxable'] ?? 0));
        }

        $lineTotal = static::round(array_sum(array_column($groups, 'line_total')));
        $allowanceTotal = static::round(min($additionalDiscount, $lineTotal));
        $remaining = $allowanceTotal;
        $keys = array_keys($groups);

        foreach ($keys as $n => $key) {
            $share = $n === count($keys) - 1
                ? $remaining
                : static::round($lineTotal > 0 ? $allowanceTotal * $groups[$key]['line_total'] / $lineTotal : 0);
            $remaining = static::round($remaining - $share);
            $groups[$key]['allowance'] = $share;
            $groups[$key]['taxable'] = static::round($groups[$key]['line_total'] - $share);
            $groups[$key]['tax'] = static::round($groups[$key]['taxable'] * $groups[$key]['rate'] / 100);
        }

        return $groups;
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
        $addDiscount = static::round(min($addDiscount, static::round($taxable)));

        $tax = 0.0;
        foreach (static::vatGroups($calculated, $addDiscount) as $group) {
            $tax += $group['tax'];
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
