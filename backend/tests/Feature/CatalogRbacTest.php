<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CatalogRbacTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_can_read_catalog_but_not_write(): void
    {
        [$merchant] = $this->merchantWithUser();
        $product = Product::factory()->forMerchant($merchant)->create();
        $staff = User::factory()->forMerchant($merchant)->create();
        $staff->assignRole(Roles::MERCHANT_STAFF);
        $token = $this->tokenFor($staff);

        $this->withToken($token)->getJson('/api/v1/products')->assertOk();
        $this->withToken($token)->getJson("/api/v1/products/{$product->id}")->assertOk();
        $this->withToken($token)->getJson('/api/v1/categories')->assertOk();

        $this->withToken($token)->postJson('/api/v1/products', ['name' => 'X', 'price' => 1])->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/products/{$product->id}", ['name' => 'Y'])->assertForbidden();
        $this->withToken($token)->deleteJson("/api/v1/products/{$product->id}")->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/categories', ['name' => 'C'])->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
        $this->postJson('/api/v1/categories', ['name' => 'X'])->assertUnauthorized();
    }
}
