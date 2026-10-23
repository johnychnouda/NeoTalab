<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StaffDashboardTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_shop_staff_cannot_sign_in_to_the_dashboard(): void
    {
        [$merchant] = $this->merchantWithUser();
        $staff = User::factory()->forMerchant($merchant)->create(['password' => 'staff-pass-123']);
        $staff->assignRole(Roles::MERCHANT_STAFF);

        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'staff-pass-123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_shop_managers_still_sign_in(): void
    {
        [$merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_ADMIN, ['name' => 'Shop']);
        $owner->forceFill(['password' => 'manager-pass-123'])->save();

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'manager-pass-123'])->assertOk();
    }
}
