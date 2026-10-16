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

    public function test_staff_cannot_manage_the_team(): void
    {
        [$merchant] = $this->merchantWithUser();
        $staff = User::factory()->forMerchant($merchant)->create();
        $staff->assignRole(Roles::MERCHANT_STAFF);
        $token = $this->tokenFor($staff);

        $this->withToken($token)->getJson('/api/v1/drivers')->assertOk();
        $this->withToken($token)->postJson('/api/v1/drivers', ['name' => 'x', 'phone' => '+96170000000'])->assertForbidden();
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

    public function test_a_shop_cannot_rewrite_its_settings_through_the_profile_update(): void
    {
        [$merchant, $user] = $this->merchantWithUser();
        $merchant->update(['settings' => ['bot' => ['phone_id' => 'mine']]]);

        $this->withToken($this->tokenFor($user))->patchJson('/api/v1/merchant', [
            'name' => 'New name',
            'settings' => ['bot' => ['phone_id' => 'another-shops-number']],
        ])->assertOk();

        $this->assertSame('New name', $merchant->fresh()->name);
        $this->assertSame('mine', $merchant->fresh()->settings['bot']['phone_id']);
    }
}
