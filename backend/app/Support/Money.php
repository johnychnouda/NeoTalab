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
     * Rewrites dollar amounts the AI wrote as "2.79 USD", "USD 2.79", "$2.79" or, in French, "2,79 $ US" into "2.79$".
     * Lira amounts and anything else are left as they are.
     */
    public static function fromAiText(string $text): string
    {
        $amount = '(\d+(?:[.,]\d+)*)';

        $text = preg_replace('/(?<![\p{L}\p{N}])'.$amount.'\s*\$\s*(?:USD|US)(?![\p{L}])/iu', '$1$', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}])'.$amount.'\s*(?:USD|US\$)(?![\p{L}])/iu', '$1$', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}])USD\s*'.$amount.'/iu', '$1$', $text);
        $text = preg_replace('/(?<![\p{L}\p{N}])'.$amount.'\s*(?:دولارات|دولار)/u', '$1$', $text);
        // "$2.79" becomes "2.79$", but the "2" in "5.50$ 2 items" stays where it is.
        $text = preg_replace('/(?<![\p{N}.,])(?<![\p{N}.,]\s)\$\s*'.$amount.'/u', '$1$', $text);
        $text = preg_replace('/(?<=\d)\s*\$(?:\s*\$)+/u', '$', $text);
        $text = preg_replace('/(?<=\d)\s+\$/u', '$', $text);

        // A decimal comma before the sign (French): "29,52$" is 29.52$; "250,000$" is a thousands comma and stays.
        return preg_replace('/(?<![\p{N},.])(\d+),(\d{1,2})\$/u', '$1.$2$', $text);
    }

    public static function lbp(float $amount): string
    {
        return number_format($amount).' LBP';
    }
}
