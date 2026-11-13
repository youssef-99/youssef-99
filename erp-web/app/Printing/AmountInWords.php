<?php

namespace App\Printing;

use NumberFormatter;

/**
 * Spells an amount in Arabic or English ("فقط ألف ريال سعودي و خمسون هللة لا غير").
 * Uses the intl extension; falls back to digits when it is missing.
 */
class AmountInWords
{
    public static function format(float $amount, ?string $locale = null, ?string $currency = null, ?string $fraction = null): string
    {
        $locale ??= app()->getLocale();
        $isArabic = $locale === 'ar';
        $amount = round(abs($amount), 2);
        $whole = (int) floor($amount);
        $cents = (int) round(($amount - $whole) * 100);

        $currency ??= $isArabic ? 'ريال سعودي' : 'Saudi Riyals';
        $fraction ??= $isArabic ? 'هللة' : 'Halalas';

        $text = static::spell($whole, $locale).' '.$currency;

        if ($cents > 0) {
            $text .= ($isArabic ? ' و ' : ' and ').static::spell($cents, $locale).' '.$fraction;
        }

        return $isArabic ? "فقط {$text} لا غير" : ucfirst($text).' only';
    }

    protected static function spell(int $number, string $locale): string
    {
        if (! class_exists(NumberFormatter::class)) {
            return (string) $number;
        }

        $formatter = new NumberFormatter($locale === 'ar' ? 'ar' : 'en', NumberFormatter::SPELLOUT);

        return (string) $formatter->format($number);
    }
}
