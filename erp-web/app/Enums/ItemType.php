<?php

namespace App\Enums;

/**
 * Item type (نوع الصنف): how stock of the item is tracked.
 */
enum ItemType: string
{
    case Standard = 'standard';
    case BatchExpiry = 'batch_expiry';
    case Serial = 'serial';
    case Service = 'service';

    public function label(): string
    {
        return __(match ($this) {
            self::Standard => 'Standard',
            self::BatchExpiry => 'Expiry Date + Batch No.',
            self::Serial => 'Serial Number',
            self::Service => 'Service',
        });
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
