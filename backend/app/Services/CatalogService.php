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
 * on create. Child cleanup is done explicitly in transactions, there are no DB-level FKs
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

    public function deleteCategory(Category $category, bool $withItems = false): void
    {
        DB::transaction(function () use ($category, $withItems) {
            if ($withItems) {
                $this->deleteProducts(Product::where('category_id', $category->id)->pluck('id')->all());
            }
            // Otherwise products survive their category; they just become uncategorized.
            Product::where('category_id', $category->id)->update(['category_id' => null]);
            $category->delete();
        });
    }

    /** Start the menu over: every item and category, or only the items without a category. */
    public function clearMenu(bool $onlyUncategorized = false): int
    {
        return DB::transaction(function () use ($onlyUncategorized) {
            $ids = Product::query()->when($onlyUncategorized, fn ($q) => $q->whereNull('category_id'))->pluck('id')->all();
            $this->deleteProducts($ids);
            if (! $onlyUncategorized) {
                Category::query()->delete();
            }

            return count($ids);
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
            ->paginate(min((int) ($filters['per_page'] ?? 25), 500));
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

    /**
     * Replaces an item's sizes and option groups in one go (the dashboard editor saves them
     * together). Rows that carry an id are updated in place, so carts keep working.
     *
     * @param  array{variants?: list<array<string, mixed>>, groups?: list<array<string, mixed>>}  $data
     */
    public function syncOptions(Product $product, array $data): Product
    {
        DB::transaction(function () use ($product, $data) {
            $keep = [];
            foreach (array_values($data['variants'] ?? []) as $i => $v) {
                $variant = ProductVariant::where('product_id', $product->id)->find($v['id'] ?? null)
                    ?? new ProductVariant(['product_id' => $product->id]);
                $variant->fill(['name' => trim($v['name']), 'price' => $v['price'], 'sort_order' => $i, 'is_active' => true, 'is_available' => true]);
                $variant->product_id = $product->id;
                $variant->save();
                $keep[] = $variant->id;
            }
            ProductVariant::where('product_id', $product->id)->whereNotIn('id', $keep)->delete();

            $keepGroups = [];
            foreach (array_values($data['groups'] ?? []) as $i => $g) {
                $group = ModifierGroup::where('product_id', $product->id)->find($g['id'] ?? null)
                    ?? new ModifierGroup(['product_id' => $product->id]);
                $group->fill([
                    'name' => trim($g['name']),
                    'required' => (bool) ($g['required'] ?? false),
                    'min_select' => ($g['required'] ?? false) ? 1 : 0,
                    'max_select' => max(1, (int) ($g['max_select'] ?? 1)),
                    'sort_order' => $i,
                ]);
                $group->product_id = $product->id;
                $group->save();
                $keepGroups[] = $group->id;

                $keepMods = [];
                foreach (array_values($g['modifiers'] ?? []) as $j => $m) {
                    $modifier = Modifier::where('modifier_group_id', $group->id)->find($m['id'] ?? null)
                        ?? new Modifier(['modifier_group_id' => $group->id]);
                    $modifier->fill(['name' => trim($m['name']), 'price_delta' => $m['price_delta'] ?? 0, 'sort_order' => $j, 'is_active' => true, 'is_available' => true]);
                    $modifier->modifier_group_id = $group->id;
                    $modifier->save();
                    $keepMods[] = $modifier->id;
                }
                Modifier::where('modifier_group_id', $group->id)->whereNotIn('id', $keepMods)->delete();
            }
            $stale = ModifierGroup::where('product_id', $product->id)->whereNotIn('id', $keepGroups)->pluck('id');
            Modifier::whereIn('modifier_group_id', $stale)->delete();
            ModifierGroup::whereKey($stale)->delete();
        });

        return $product->load(['category', 'variants', 'modifierGroups.modifiers']);
    }

    public function deleteProduct(Product $product): void
    {
        DB::transaction(fn () => $this->deleteProducts([$product->id]));
    }

    /** @param  list<string>  $ids  products of the current shop, removed with their sizes and options */
    private function deleteProducts(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $groupIds = ModifierGroup::whereIn('product_id', $chunk)->pluck('id');
            Modifier::whereIn('modifier_group_id', $groupIds)->delete();
            ModifierGroup::whereKey($groupIds)->delete();
            ProductVariant::whereIn('product_id', $chunk)->delete();
            Product::whereKey($chunk)->delete();
        }
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
