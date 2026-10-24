<?php

namespace App\Support;

use App\Models\Product;

/**
 * How much of an item: a whole number, or for items sold by weight (kg) a quarter-kilo step
 * (0.25, 0.5, 1.5…). Whole amounts stay ints, so "2" is still 2 everywhere.
 */
final class Quantity
{
    public const MAX = 99;

    /** Units sold by weight, in quarter steps. */
    public const WEIGHT_UNITS = ['kg'];

    public const WEIGHT_STEP = 0.25;

    public static function byWeight(?Product $product): bool
    {
        return $product !== null && in_array((string) $product->unit, self::WEIGHT_UNITS, true);
    }

    public static function step(?Product $product): float
    {
        return self::byWeight($product) ? self::WEIGHT_STEP : 1.0;
    }

    /** A number as an int when it is whole, else a float. */
    public static function from(mixed $value): int|float
    {
        $number = is_numeric($value) ? (float) $value : (float) str_replace(',', '.', (string) $value);

        return self::tidy($number);
    }

    /**
     * The amount the shop can sell: rounded to the item's step, at least one step, at most MAX.
     */
    public static function normalize(mixed $value, ?Product $product): int|float
    {
        $step = self::step($product);
        $number = round(self::from($value) / $step) * $step;

        return self::tidy(max($step, min(self::MAX, $number)));
    }

    /** "2", "1.5", "0.25" (no trailing zeros). */
    public static function format(mixed $value): string
    {
        $number = self::from($value);

        return is_int($number) ? (string) $number : rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }

    /** "2× Shawarma", or for a weighed line "1.5 kg Tomatoes". */
    public static function line(mixed $quantity, ?string $unit, string $name): string
    {
        return in_array((string) $unit, self::WEIGHT_UNITS, true)
            ? self::format($quantity)." {$unit} {$name}"
            : self::format($quantity)."× {$name}";
    }

    private static function tidy(float $number): int|float
    {
        $number = round($number, 3);

        return floor($number) == $number ? (int) $number : $number;
    }
}
