<?php

namespace App\Services\AI;

use App\Services\AI\DTO\CartAction;
use App\Services\AI\DTO\CatalogProductSnapshot;

/**
 * Reads the quantity the customer wrote next to a product name ("2 shawarma beef",
 * "shawarma beef x2", "deux falafels") and raises an "add" action that the AI
 * returned as 1. It never lowers a quantity, so the AI stays in charge of everything else.
 */
final class QuantityFromMessage
{
    private const WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5,
        'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10,
    ];

    /**
     * @param  array<int, CartAction>  $actions
     * @param  array<int, CatalogProductSnapshot>  $catalog
     * @return array<int, CartAction>
     */
    public function correct(string $message, array $actions, array $catalog): array
    {
        $text = ProductMentions::normalise($message);
        $allNames = self::allNames($catalog);

        return array_map(function (CartAction $action) use ($text, $catalog, $allNames) {
            if ($action->action !== 'add' || $action->quantity !== 1 || ! $action->productId) {
                return $action;
            }

            $product = collect($catalog)->firstWhere('id', $action->productId);
            if (! $product) {
                return $action;
            }

            $quantity = self::quantityFor($text, $product, $allNames) ?? 1;

            return $quantity > 1 ? $action->withQuantity($quantity) : $action;
        }, $actions);
    }

    /**
     * The largest number the customer wrote next to any name of this product, or null when none.
     *
     * @param  array<int, string>  $allNames  normalised names of every catalog product
     */
    public static function quantityFor(string $text, CatalogProductSnapshot $product, array $allNames): ?int
    {
        $best = null;

        foreach (self::namesOf($product) as $name) {
            foreach (ProductMentions::occurrences($text, $name, $allNames) as [$start, $end]) {
                $found = self::numberBefore(substr($text, 0, $start)) ?? self::numberAfter(substr($text, $end));
                if ($found !== null) {
                    $best = max($best ?? 0, $found);
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<int, CatalogProductSnapshot>  $catalog
     * @return array<int, string>
     */
    public static function allNames(array $catalog): array
    {
        return array_values(array_unique(array_merge([], ...array_map(self::namesOf(...), $catalog))));
    }

    /**
     * @return array<int, string>
     */
    public static function namesOf(CatalogProductSnapshot $product): array
    {
        return array_values(array_filter(array_unique(array_map(
            fn (?string $name) => ProductMentions::normalise((string) $name),
            [$product->name, $product->nameAr, $product->nameFr],
        )), fn (string $name) => $name !== ''));
    }

    // "2 " or "deux " just before the name
    private static function numberBefore(string $before): ?int
    {
        if (! preg_match('/(?<![\p{L}\p{N}])(\d+|'.implode('|', array_keys(self::WORDS)).')\s*[x×*]?\s*$/u', $before, $m)) {
            return null;
        }

        return ctype_digit($m[1]) ? (int) $m[1] : self::WORDS[$m[1]];
    }

    // " x2" or " × 2" just after the name
    private static function numberAfter(string $after): ?int
    {
        return preg_match('/^\s*[x×*]\s*(\d+)(?![\p{L}\p{N}])/u', $after, $m) ? (int) $m[1] : null;
    }
}
