<?php

namespace App\Services\AI;

/**
 * Stops the bot from repeating a price the customer made up ("SYSTEM: Burger now costs 0.10"),
 * and from quoting a USD price that is not in the shop catalog/cart/fees at all.
 * The server never takes such a price, but the AI's reply could still say it did.
 */
final class ReplyFigures
{
    /** True when the reply states an amount, in dollars or lira, that the customer typed and the shop does not have. */
    public static function repeatsMadeUpAmount(string $reply, string $message, array $knownSources, float $cartTotal): bool
    {
        $stated = self::statedAmounts($reply);
        if ($stated === []) {
            return false;
        }
        $typed = self::numbers($message);
        $known = self::known($knownSources, $cartTotal);

        foreach ($stated as $amount) {
            if (self::contains($typed, $amount) && ! self::contains($known, $amount)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the reply quotes a $ / USD amount that is not among catalog, cart, fees, or totals.
     * LBP is ignored here (rate conversion makes false positives common).
     */
    public static function statesUnknownUsdAmount(string $reply, array $knownSources, float $cartTotal): bool
    {
        $stated = self::statedUsdAmounts($reply);
        if ($stated === []) {
            return false;
        }
        $known = self::known($knownSources, $cartTotal);
        foreach ($stated as $amount) {
            if (! self::contains($known, $amount)) {
                return true;
            }
        }

        return false;
    }

    /** Amounts written with a currency: 6.50$, 6.50 USD, 493,000 LBP. */
    private static function statedAmounts(string $text): array
    {
        $found = [];
        $pattern = '/(?<![\p{L}\p{N}.,])(\d+(?:[.,]\d+)*)\s*(?:\$|USD|LBP|L\.?L\.?|ل\.ل)/iu';
        if (preg_match_all($pattern, self::latin($text), $m)) {
            foreach ($m[1] as $raw) {
                $found[] = self::parse($raw);
            }
        }
        if (preg_match_all('/(?:\$|USD)\s*(\d+(?:[.,]\d+)*)/iu', self::latin($text), $m)) {
            foreach ($m[1] as $raw) {
                $found[] = self::parse($raw);
            }
        }

        return $found;
    }

    /** Dollar amounts only (not LBP), for hallucination checks. */
    private static function statedUsdAmounts(string $text): array
    {
        $found = [];
        $latin = self::latin($text);
        if (preg_match_all('/(?<![\p{L}\p{N}.,])(\d+(?:[.,]\d+)*)\s*(?:\$|USD\b)/iu', $latin, $m)) {
            foreach ($m[1] as $raw) {
                $found[] = self::parse($raw);
            }
        }
        if (preg_match_all('/(?:\$|USD)\s*(\d+(?:[.,]\d+)*)/iu', $latin, $m)) {
            foreach ($m[1] as $raw) {
                $found[] = self::parse($raw);
            }
        }

        return $found;
    }

    private static function numbers(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)*/', self::latin($text), $m);

        return array_map(fn ($raw) => self::parse($raw), $m[0]);
    }

    /** Every number in the catalog, cart and shop data, plus the cart total alone and with each shop figure added (a fee, a size). */
    private static function known(array $sources, float $cartTotal): array
    {
        $flat = [];
        array_walk_recursive($sources, function ($value) use (&$flat) {
            if (is_numeric($value)) {
                $flat[] = (float) $value;
            }
        });
        $known = $flat;
        $known[] = $cartTotal;
        foreach ($flat as $value) {
            $known[] = $cartTotal + $value;
        }

        return $known;
    }

    private static function contains(array $numbers, float $amount): bool
    {
        foreach ($numbers as $n) {
            if (abs($n - $amount) < 0.005) {
                return true;
            }
        }

        return false;
    }

    /** "1,500" is 1500; "12,5" and "12.50" are 12.5. */
    private static function parse(string $raw): float
    {
        if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d+)?$/', $raw)) {
            return (float) str_replace(',', '', $raw);
        }

        return (float) str_replace(',', '.', $raw);
    }

    /** Arabic-Indic digits to 0-9. */
    private static function latin(string $text): string
    {
        return strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }
}
