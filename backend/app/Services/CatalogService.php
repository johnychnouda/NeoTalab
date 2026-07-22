<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * All catalog reads/writes for the active tenant. Every model here is tenant-scoped
 * (BelongsToTenant), so lookups can never cross merchants and merchant_id is auto-filled
 * on create. Child cleanup is done explicitly in transactions — there are no DB-level FKs
 * (SQLite-safe convention, integrity enforced at the application layer).
 */
class CatalogService
{
    // ── Categories ───────────────────────────────────────────

    public function listCategories()
    {
        return Category::withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function createCategory(array $data): Category
    {
        return Category::create($data);
    }

    public function updateCategory(Category $category, array $data): Category
    {
        $category->update($data);

        return $category;
    }

    public function deleteCategory(Category $category): void
    {
        DB::transaction(function () use ($category) {
            // Products survive their category; they just become uncategorized.
            Product::where('category_id', $category->id)->update(['category_id' => null]);
            $category->delete();
        });
    }

    // ── Products ─────────────────────────────────────────────

    /**
     * @param  array{q?:string,category_id?:string,is_active?:bool,is_available?:bool,per_page?:int}  $filters
     */
    public function listProducts(array $filters = []): LengthAwarePaginator
    {
        return Product::with(['category', 'variants', 'modifierGroups.modifiers'])
            ->when($filters['q'] ?? null, function ($query, $q) {
                $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('name_ar', 'like', "%{$q}%")
                    ->orWhere('name_fr', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%"));
            })
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))
            ->when(isset($filters['is_active']), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->when(isset($filters['is_available']), fn ($query) => $query->where('is_available', (bool) $filters['is_available']))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function createProduct(array $data): Product
    {
        return Product::create($data)->load('category');
    }

    public function updateProduct(Product $product, array $data): Product
    {
        $product->update($data);

        return $product->load(['category', 'variants', 'modifierGroups.modifiers']);
    }

    public function deleteProduct(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $groupIds = ModifierGroup::where('product_id', $product->id)->pluck('id');
            Modifier::whereIn('modifier_group_id', $groupIds)->delete();
            ModifierGroup::whereKey($groupIds)->delete();
            ProductVariant::where('product_id', $product->id)->delete();
            $product->delete();
        });
    }

    // ── Variants ─────────────────────────────────────────────

    public function addVariant(Product $product, array $data): ProductVariant
    {
        return $product->variants()->create($data);
    }

    public function updateVariant(ProductVariant $variant, array $data): ProductVariant
    {
        $variant->update($data);

        return $variant;
    }

    // ── Modifiers ────────────────────────────────────────────

    public function addModifierGroup(Product $product, array $data): ModifierGroup
    {
        return $product->modifierGroups()->create($data);
    }

    public function updateModifierGroup(ModifierGroup $group, array $data): ModifierGroup
    {
        $group->update($data);

        return $group->load('modifiers');
    }

    public function deleteModifierGroup(ModifierGroup $group): void
    {
        DB::transaction(function () use ($group) {
            Modifier::where('modifier_group_id', $group->id)->delete();
            $group->delete();
        });
    }

    public function addModifier(ModifierGroup $group, array $data): Modifier
    {
        return $group->modifiers()->create($data);
    }

    public function updateModifier(Modifier $modifier, array $data): Modifier
    {
        $modifier->update($data);

        return $modifier;
    }
}
