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

    /**
     * Rewrites dollar amounts the AI wrote as "2.79 USD", "USD 2.79" or "$2.79" into "2.79$".
     * Lira amounts and anything else are left as they are.
     */
    public static function fromAiText(string $text): string
    {
        $amount = '(\d+(?:[.,]\d+)*)';

        $text = preg_replace('/(?<![\p{L}\p{N}])'.$amount.'\s*(?:USD|US\$)(?![\p{L}])/iu', '$1$', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}])USD\s*'.$amount.'/iu', '$1$', $text);

        return preg_replace('/\$\s*'.$amount.'/u', '$1$', $text);
    }

    public static function lbp(float $amount): string
    {
        return number_format($amount).' LBP';
    }
}
