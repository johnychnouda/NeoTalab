<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_sees_all_merchants_across_tenants(): void
    {
        $this->merchantWithUser();
        $this->merchantWithUser();
        $admin = $this->platformAdmin();

        $response = $this->withToken($this->tokenFor($admin))->getJson('/api/v1/admin/merchants')->assertOk();

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_non_admin_cannot_access_admin_endpoints(): void
    {
        [, $owner] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($owner))->getJson('/api/v1/admin/merchants')->assertForbidden();
    }

    public function test_admin_can_provision_a_new_merchant_with_owner(): void
    {
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))->postJson('/api/v1/admin/merchants/provision', [
            'business_name' => 'Pharma One',
            'business_type' => 'pharmacy',
            'owner_name' => 'Owner One',
            'owner_email' => 'owner@pharma.com',
            'owner_password' => 'password123',
        ])->assertCreated()->assertJsonPath('data.name', 'Pharma One');

        $this->assertDatabaseHas('users', ['email' => 'owner@pharma.com']);
        $owner = User::where('email', 'owner@pharma.com')->firstOrFail();
        $this->assertTrue($owner->hasRole(Roles::MERCHANT_OWNER));
    }
}
