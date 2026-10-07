<?php

namespace App\Support;

/**
 * Single French number-formatting helper shared by the site report's HTML
 * mail, PDF Blade view and SiteReportService's "En bref" summary — so the
 * three never drift into different locale conventions (a decimal comma here,
 * a decimal dot there was exactly the bug this class fixes).
 *
 * French typographic convention: comma decimal separator, narrow no-break
 * space (U+202F) as the thousands separator.
 */
final class ReportFormatter
{
    private const THOUSANDS_SEPARATOR = "\u{202F}";

    /**
     * Plain number, French locale. Null in, null out — callers decide the
     * "—" placeholder, this class never invents display fallbacks.
     */
    public static function number(?float $value, int $decimals = 0): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format($value, $decimals, ',', self::THOUSANDS_SEPARATOR);
    }

    public static function percent(?float $value, int $decimals = 1): ?string
    {
        $formatted = self::number($value, $decimals);

        return $formatted === null ? null : $formatted.' %';
    }

    public static function milliseconds(?float $value, int $decimals = 0): ?string
    {
        $formatted = self::number($value, $decimals);

        return $formatted === null ? null : $formatted.' ms';
    }

    /**
     * A signed delta, e.g. "+2,7 %", "-4,8 pt", "-0,7". Always shows a sign
     * (including "+" for positive), which is what tells a reader "this is a
     * change" rather than an absolute value.
     */
    public static function signed(float $value, int $decimals = 0, string $unit = ''): string
    {
        $sign = $value > 0 ? '+' : ($value < 0 ? '-' : '');

        return $sign.self::number(abs($value), $decimals).$unit;
    }
}
