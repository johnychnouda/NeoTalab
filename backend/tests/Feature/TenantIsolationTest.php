<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Milestone 1 acceptance gate: a merchant can never read or write another merchant's data.
 */
class TenantIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_listing_is_scoped_to_own_merchant(): void
    {
        [$merchantA, $ownerA] = $this->merchantWithUser();
        User::factory()->forMerchant($merchantA)->create(); // extra staff in A

        [$merchantB] = $this->merchantWithUser();
        User::factory()->forMerchant($merchantB)->create(); // staff in B

        $response = $this->withToken($this->tokenFor($ownerA))->getJson('/api/v1/users')->assertOk();

        $merchantIds = collect($response->json('data'))->pluck('merchant_id')->unique()->values();
        $this->assertEquals([$merchantA->id], $merchantIds->all());
    }

    public function test_merchant_endpoint_returns_only_own_merchant(): void
    {
        [$merchantA, $ownerA] = $this->merchantWithUser(merchantAttrs: ['name' => 'Alpha']);
        $this->merchantWithUser(merchantAttrs: ['name' => 'Bravo']);

        $this->withToken($this->tokenFor($ownerA))->getJson('/api/v1/merchant')
            ->assertOk()
            ->assertJsonPath('data.id', $merchantA->id)
            ->assertJsonPath('data.name', 'Alpha');
    }

    public function test_cannot_update_user_from_another_merchant(): void
    {
        [, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        $staffB = User::factory()->forMerchant($merchantB)->create(['name' => 'Original']);
        $staffB->assignRole(Roles::MERCHANT_STAFF);

        $this->withToken($this->tokenFor($ownerA))
            ->patchJson("/api/v1/users/{$staffB->id}", ['name' => 'Hacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $staffB->id, 'name' => 'Original']);
    }

    public function test_cannot_delete_user_from_another_merchant(): void
    {
        [, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        $staffB = User::factory()->forMerchant($merchantB)->create();

        $this->withToken($this->tokenFor($ownerA))
            ->deleteJson("/api/v1/users/{$staffB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $staffB->id]);
    }

    public function test_created_user_is_bound_to_actors_tenant(): void
    {
        [$merchantA, $ownerA] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($ownerA))->postJson('/api/v1/users', [
            'name' => 'New Staff',
            'email' => 'staff@a.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => Roles::MERCHANT_STAFF,
        ])->assertCreated()->assertJsonPath('data.merchant_id', $merchantA->id);

        $this->assertDatabaseHas('users', ['email' => 'staff@a.com', 'merchant_id' => $merchantA->id]);
    }
}
