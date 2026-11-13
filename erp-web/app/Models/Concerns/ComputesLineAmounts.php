<?php

namespace App\Models\Concerns;

use App\Services\Calc\DocumentCalculator;

/**
 * Document lines always (re)compute gross / discount / tax / net on save,
 * so the database never trusts amounts typed or posted from the browser.
 */
trait ComputesLineAmounts
{
    protected static function bootComputesLineAmounts(): void
    {
        static::saving(function ($line) {
            $attributes = $line->getAttributes();
            $c = DocumentCalculator::line([
                'quantity' => $attributes['quantity'] ?? 0,
                'unit_price' => $attributes['unit_price'] ?? $attributes['price'] ?? 0,
                'discount' => $attributes['discount'] ?? 0,
                'discount_percent' => $attributes['discount_percent'] ?? 0,
                'discount2' => $attributes['discount2'] ?? 0,
                'discount2_percent' => $attributes['discount2_percent'] ?? 0,
                'bonus_qty' => $attributes['bonus_qty'] ?? 0,
                'bonus_percent' => $attributes['bonus_percent'] ?? 0,
                'tax_percent' => $attributes['tax_percent'] ?? 0,
            ]);

            static $cache = [];
            $columns = $cache[$line->getTable()] ??= array_flip($line->getConnection()->getSchemaBuilder()->getColumnListing($line->getTable()));
            foreach (['gross', 'discount', 'discount2', 'tax', 'net', 'unit_net', 'bonus_qty'] as $field) {
                if (isset($columns[$field])) {
                    $line->setAttribute($field, $c[$field]);
                }
            }
            foreach (['quantity', 'unit_price', 'price', 'discount_percent', 'discount2_percent', 'bonus_percent', 'tax_percent', 'free_qty', 'system_qty'] as $field) {
                if (isset($columns[$field]) && $line->getAttribute($field) === null) {
                    $line->setAttribute($field, 0);
                }
            }
        });
    }
}
