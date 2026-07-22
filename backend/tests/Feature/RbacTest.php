<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_cannot_manage_users(): void
    {
        [$merchant] = $this->merchantWithUser();
        $staff = User::factory()->forMerchant($merchant)->create();
        $staff->assignRole(Roles::MERCHANT_STAFF);
        $token = $this->tokenFor($staff);

        $this->withToken($token)->getJson('/api/v1/users')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/users', [
            'name' => 'x', 'email' => 'x@a.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => Roles::MERCHANT_STAFF,
        ])->assertForbidden();
    }

    public function test_staff_can_view_merchant_but_not_update(): void
    {
        [$merchant] = $this->merchantWithUser();
        $staff = User::factory()->forMerchant($merchant)->create();
        $staff->assignRole(Roles::MERCHANT_STAFF);
        $token = $this->tokenFor($staff);

        $this->withToken($token)->getJson('/api/v1/merchant')->assertOk();
        $this->withToken($token)->patchJson('/api/v1/merchant', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_owner_can_create_staff(): void
    {
        [, $owner] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($owner))->postJson('/api/v1/users', [
            'name' => 'Staffer', 'email' => 'staffer@a.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => Roles::MERCHANT_STAFF,
        ])->assertCreated()->assertJsonPath('data.roles', [Roles::MERCHANT_STAFF]);
    }

    public function test_manager_cannot_grant_elevated_roles(): void
    {
        [, $owner] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($owner))->postJson('/api/v1/users', [
            'name' => 'x', 'email' => 'x@a.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => Roles::PLATFORM_SUPER_ADMIN,
        ])->assertStatus(422)->assertJsonValidationErrorFor('role');
    }
}
