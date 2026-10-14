<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_owner_can_build_a_full_product(): void
    {
        [, $owner] = $this->merchantWithUser();
        $token = $this->tokenFor($owner);

        $categoryId = $this->withToken($token)->postJson('/api/v1/categories', ['name' => 'Drinks'])
            ->assertCreated()->json('data.id');

        $productId = $this->withToken($token)->postJson('/api/v1/products', [
            'name' => 'Fresh Juice',
            'price' => 6.50,
            'category_id' => $categoryId,
            'sku' => 'JUICE-1',
        ])->assertCreated()->assertJsonPath('data.category.name', 'Drinks')->json('data.id');

        $this->withToken($token)->postJson("/api/v1/products/{$productId}/variants", [
            'name' => 'Large', 'price' => 8.00,
        ])->assertCreated();

        $groupId = $this->withToken($token)->postJson("/api/v1/products/{$productId}/modifier-groups", [
            'name' => 'Extras', 'min_select' => 0, 'max_select' => 3,
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/v1/modifier-groups/{$groupId}/modifiers", [
            'name' => 'Ginger', 'price_delta' => 0.75,
        ])->assertCreated();

        // Full tree comes back on show
        $this->withToken($token)->getJson("/api/v1/products/{$productId}")
            ->assertOk()
            ->assertJsonPath('data.variants.0.name', 'Large')
            ->assertJsonPath('data.modifier_groups.0.modifiers.0.name', 'Ginger');
    }

    public function test_product_listing_supports_search_filter_and_pagination(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $category = Category::factory()->forMerchant($merchant)->create();
        Product::factory()->forMerchant($merchant)->create(['name' => 'Shawarma Wrap', 'category_id' => $category->id]);
        Product::factory()->forMerchant($merchant)->count(3)->create();
        $token = $this->tokenFor($owner);

        $this->withToken($token)->getJson('/api/v1/products?q=shawarma')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Shawarma Wrap');

        $this->withToken($token)->getJson("/api/v1/products?category_id={$category->id}")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($token)->getJson('/api/v1/products?per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4);
    }

    public function test_inventory_flags_drive_orderable_state(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $product = Product::factory()->forMerchant($merchant)->tracked(stock: 0, threshold: 5)->create();

        $this->withToken($this->tokenFor($owner))->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.is_orderable', false)
            ->assertJsonPath('data.is_low_stock', true);
    }

    public function test_validation_rules(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $token = $this->tokenFor($owner);

        // price required
        $this->withToken($token)->postJson('/api/v1/products', ['name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrorFor('price');

        // counted inventory requires a stock quantity
        $this->withToken($token)->postJson('/api/v1/products', [
            'name' => 'X', 'price' => 1, 'track_inventory' => true,
        ])->assertStatus(422)->assertJsonValidationErrorFor('stock_quantity');

        // max_select must be >= min_select
        $product = Product::factory()->forMerchant($merchant)->create();
        $this->withToken($token)->postJson("/api/v1/products/{$product->id}/modifier-groups", [
            'name' => 'Bad', 'min_select' => 3, 'max_select' => 1,
        ])->assertStatus(422)->assertJsonValidationErrorFor('max_select');

        // duplicate SKU within the tenant
        Product::factory()->forMerchant($merchant)->create(['sku' => 'DUP-1']);
        $this->withToken($token)->postJson('/api/v1/products', [
            'name' => 'Y', 'price' => 2, 'sku' => 'DUP-1',
        ])->assertStatus(422)->assertJsonValidationErrorFor('sku');
    }

    public function test_deleting_category_uncategorizes_products(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $category = Category::factory()->forMerchant($merchant)->create();
        $product = Product::factory()->forMerchant($merchant)->create(['category_id' => $category->id]);

        $this->withToken($this->tokenFor($owner))->deleteJson("/api/v1/categories/{$category->id}")->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'category_id' => null]);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_deleting_product_cascades_children(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $product = Product::factory()->forMerchant($merchant)->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();
        $group = ModifierGroup::factory()->forProduct($product)->create();
        $modifier = Modifier::factory()->forGroup($group)->create();

        $this->withToken($this->tokenFor($owner))->deleteJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);
        $this->assertDatabaseMissing('modifier_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('modifiers', ['id' => $modifier->id]);
    }
}
