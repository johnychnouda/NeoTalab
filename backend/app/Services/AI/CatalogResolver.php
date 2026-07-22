<?php

namespace App\Services\AI;

use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AI\DTO\CatalogProductSnapshot;
use Illuminate\Support\Collection;

/**
 * Builds catalog snapshots for the AI layer and validates/resolves catalog references
 * for cart mutations — always tenant-scoped via BelongsToTenant global scope.
 */
class CatalogResolver
{
    /**
     * @return Collection<int, CatalogProductSnapshot>
     */
    public function buildCatalogSnapshot(): Collection
    {
        return Product::with(['variants', 'modifierGroups.modifiers'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => $this->snapshotProduct($product));
    }

    public function resolveProduct(string $productId): ?Product
    {
        return Product::with(['variants', 'modifierGroups.modifiers'])->find($productId);
    }

    public function resolveVariant(Product $product, ?string $variantId): ?ProductVariant
    {
        if ($variantId === null) {
            return null;
        }

        return $product->variants->firstWhere('id', $variantId)
            ?? ProductVariant::where('product_id', $product->id)->find($variantId);
    }

    /**
     * @param  array<int, string>  $modifierIds
     * @return Collection<int, Modifier>
     */
    public function resolveModifiers(Product $product, array $modifierIds): Collection
    {
        if ($modifierIds === []) {
            return collect();
        }

        $allowedIds = $product->modifierGroups
            ->flatMap(fn ($g) => $g->modifiers)
            ->pluck('id')
            ->all();

        $valid = array_values(array_intersect($modifierIds, $allowedIds));

        return Modifier::whereIn('id', $valid)->get();
    }

    /**
     * @param  Collection<int, Modifier>  $modifiers
     */
    public function calculateUnitPrice(Product $product, ?ProductVariant $variant, Collection $modifiers): string
    {
        $base = $variant?->price ?? $product->price;
        $delta = $modifiers->sum(fn (Modifier $m) => (float) $m->price_delta);

        return number_format((float) $base + $delta, 2, '.', '');
    }

    private function snapshotProduct(Product $product): CatalogProductSnapshot
    {
        return new CatalogProductSnapshot(
            id: $product->id,
            name: $product->name,
            nameAr: $product->name_ar,
            nameFr: $product->name_fr,
            price: number_format((float) $product->price, 2, '.', ''),
            isOrderable: $product->isOrderable(),
            variants: $product->variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'price' => $v->price !== null ? number_format((float) $v->price, 2, '.', '') : null,
            ])->values()->all(),
            modifierGroups: $product->modifierGroups->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'min_select' => $g->min_select,
                'max_select' => $g->max_select,
                'modifiers' => $g->modifiers->map(fn (Modifier $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'price_delta' => number_format((float) $m->price_delta, 2, '.', ''),
                ])->values()->all(),
            ])->values()->all(),
        );
    }
}
