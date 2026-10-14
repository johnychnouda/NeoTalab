<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * M2 acceptance gate: the catalog is invisible and untouchable across tenants,
 * including indirect access via nested parent ids and foreign keys.
 */
class CatalogIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_product_listing_only_shows_own_tenant(): void
    {
        [$merchantA, $ownerA] = $this->merchantWithUser();
        Product::factory()->forMerchant($merchantA)->count(2)->create();

        [$merchantB] = $this->merchantWithUser();
        Product::factory()->forMerchant($merchantB)->count(3)->create();

        $this->withToken($this->tokenFor($ownerA))->getJson('/api/v1/products')
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_cannot_read_update_or_delete_foreign_product(): void
    {
        [, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        $productB = Product::factory()->forMerchant($merchantB)->create(['name' => 'Original']);
        $token = $this->tokenFor($ownerA);

        $this->withToken($token)->getJson("/api/v1/products/{$productB->id}")->assertNotFound();
        $this->withToken($token)->patchJson("/api/v1/products/{$productB->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->withToken($token)->deleteJson("/api/v1/products/{$productB->id}")->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $productB->id, 'name' => 'Original']);
    }

    public function test_cannot_attach_variant_to_foreign_product(): void
    {
        [, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        $productB = Product::factory()->forMerchant($merchantB)->create();

        $this->withToken($this->tokenFor($ownerA))
            ->postJson("/api/v1/products/{$productB->id}/variants", ['name' => 'Sneaky'])
            ->assertNotFound();

        $this->assertDatabaseMissing('product_variants', ['product_id' => $productB->id]);
    }

    public function test_cannot_use_foreign_category_id_on_own_product(): void
    {
        [, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        $categoryB = Category::factory()->forMerchant($merchantB)->create();

        // category_id validation is tenant-scoped → foreign id is "does not exist"
        $this->withToken($this->tokenFor($ownerA))->postJson('/api/v1/products', [
            'name' => 'Trick', 'price' => 5, 'category_id' => $categoryB->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('category_id');
    }

    public function test_same_sku_is_allowed_in_different_tenants(): void
    {
        [$merchantA, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        Product::factory()->forMerchant($merchantB)->create(['sku' => 'SHARED-SKU']);

        $this->withToken($this->tokenFor($ownerA))->postJson('/api/v1/products', [
            'name' => 'Mine', 'price' => 3, 'sku' => 'SHARED-SKU',
        ])->assertCreated();

        $this->assertDatabaseHas('products', ['merchant_id' => $merchantA->id, 'sku' => 'SHARED-SKU']);
    }
}
