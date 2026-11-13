<?php

namespace App\Enums;

/**
 * Item nature (طبيعة الصنف).
 */
enum ItemNature: string
{
    case Stock = 'stock';
    case Service = 'service';
    case Consumable = 'consumable';

    public function label(): string
    {
        return __(match ($this) {
            self::Stock => 'Stock Item',
            self::Service => 'Service',
            self::Consumable => 'Consumable',
        });
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
