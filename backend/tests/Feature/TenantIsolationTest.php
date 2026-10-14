<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Merchant;
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

    private function teamMember(Merchant $merchant, string $name = 'Rami'): Driver
    {
        return Driver::query()->forceCreate([
            'merchant_id' => $merchant->id, 'name' => $name, 'role' => 'driver', 'phone' => '+9617'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'off_duty', 'availability' => 'available', 'is_active' => true,
        ]);
    }

    public function test_team_listing_is_scoped_to_own_merchant(): void
    {
        [$merchantA, $ownerA] = $this->merchantWithUser();
        $this->teamMember($merchantA);
        [$merchantB] = $this->merchantWithUser();
        $this->teamMember($merchantB);

        $response = $this->withToken($this->tokenFor($ownerA))->getJson('/api/v1/drivers')->assertOk();

        $this->assertEquals([$merchantA->id], collect($response->json('drivers'))->pluck('merchant_id')->unique()->values()->all());
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

    public function test_cannot_update_team_member_from_another_merchant(): void
    {
        [, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        $memberB = $this->teamMember($merchantB);

        $this->withToken($this->tokenFor($ownerA))
            ->postJson("/api/v1/drivers/{$memberB->id}/status", ['status' => 'on_duty'])
            ->assertNotFound();

        $this->assertSame('off_duty', $memberB->fresh()->status);
    }

    public function test_cannot_remove_team_member_from_another_merchant(): void
    {
        [, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        $memberB = $this->teamMember($merchantB);

        $this->withToken($this->tokenFor($ownerA))
            ->deleteJson("/api/v1/drivers/{$memberB->id}")
            ->assertNotFound();

        $this->assertTrue($memberB->fresh()->is_active);
    }

    public function test_added_team_member_is_bound_to_actors_tenant(): void
    {
        [$merchantA, $ownerA] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($ownerA))->postJson('/api/v1/drivers', ['name' => 'New Driver', 'phone' => '+96170111222'])
            ->assertCreated();

        $this->assertDatabaseHas('drivers', ['name' => 'New Driver', 'merchant_id' => $merchantA->id]);
    }
}
