<?php

namespace App\Support;

/**
 * Amounts as customers, staff and drivers read them in WhatsApp: "5.50$" (the sign after the
 * amount, never "USD"), "493,000 LBP", or "5.50 EUR" for other currencies.
 */
final class Money
{
    public static function format(float $amount, ?string $currency): string
    {
        return match (strtoupper((string) ($currency ?: 'USD'))) {
            'USD' => number_format($amount, 2).'$',
            'LBP' => self::lbp($amount),
            default => number_format($amount, 2).' '.strtoupper((string) $currency),
        };
    }

    public static function lbp(float $amount): string
    {
        return number_format($amount).' LBP';
    }
}
