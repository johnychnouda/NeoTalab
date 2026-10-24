<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Collection;

/**
 * Stock per size and option (clothing: M in Red). Only for items that count stock. A line is
 * limited by the row for its size and each chosen option (or that option in every size); options
 * without a row are not limited.
 */
final class OptionStock
{
    /** @return Collection<int, ProductStock> the rows that limit this line */
    public static function rows(Product $product, ?string $variantId, array $modifierIds): Collection
    {
        if (! $product->track_inventory || $modifierIds === []) {
            return collect();
        }
        $all = ProductStock::query()->where('product_id', $product->id)->whereIn('modifier_id', $modifierIds)->get();

        return collect($modifierIds)->map(fn ($m) => $all->first(fn ($r) => $r->modifier_id === $m && $r->variant_id === $variantId)
            ?? $all->first(fn ($r) => $r->modifier_id === $m && $r->variant_id === null))->filter()->values();
    }

    /** How many are left for this size and these options; null = not limited. */
    public static function available(Product $product, ?string $variantId, array $modifierIds): int|float|null
    {
        $rows = self::rows($product, $variantId, $modifierIds);

        return $rows->isEmpty() ? null : $rows->min('stock_quantity');
    }

    public static function has(Product $product, ?string $variantId, array $modifierIds, int|float $quantity): bool
    {
        $left = self::available($product, $variantId, $modifierIds);

        return $left === null || $left >= $quantity;
    }

    /** Takes stock only if it is still there (a parallel checkout may have taken it); false when not. */
    public static function take(Product $product, ?string $variantId, array $modifierIds, int|float $quantity): bool
    {
        foreach (self::rows($product, $variantId, $modifierIds) as $row) {
            if (ProductStock::query()->whereKey($row->id)->where('stock_quantity', '>=', $quantity)->decrement('stock_quantity', $quantity) === 0) {
                return false;
            }
        }

        return true;
    }

    public static function giveBack(Product $product, ?string $variantId, array $modifierIds, int|float $quantity): void
    {
        foreach (self::rows($product, $variantId, $modifierIds) as $row) {
            ProductStock::query()->whereKey($row->id)->increment('stock_quantity', $quantity);
        }
    }
}
