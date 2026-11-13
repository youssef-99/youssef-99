<?php

namespace App\Reports\Support;

/**
 * Translator for report strings.
 *
 * Report translations live in `lang/modules/reports/ar.json` under keys prefixed with "rpt."
 * (e.g. "rpt.Credit": "الدائن") so they never clash with the same English word used elsewhere
 * in the application ("Credit" is "آجل" as a payment method). When no prefixed key exists the
 * plain application translation is used, so English text always falls back to itself.
 */
class ReportLang
{
    /**
     * @param  array<string, string|int|float>  $replace
     */
    public static function get(string $text, array $replace = []): string
    {
        $key = "rpt.{$text}";
        $translated = __($key, $replace);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        $plain = __($text, $replace);

        return is_string($plain) ? $plain : $text;
    }
}
