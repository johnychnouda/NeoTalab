<?php

namespace Tests\Feature;

use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlatformSettingsSeeder::class);
    }

    public function test_platform_admin_can_read_and_update_settings(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->tokenFor($admin);

        $this->withToken($token)->getJson('/api/v1/admin/platform-settings')
            ->assertOk()
            ->assertJsonPath('data.subscriptionPrice', 29)
            ->assertJsonPath('data.trialDays', 7);

        $this->withToken($token)->patchJson('/api/v1/admin/platform-settings', [
            'subscription_price' => 35,
            'trial_days' => 14,
            'wa_phone_id' => '12345',
        ])->assertOk()
            ->assertJsonPath('data.subscriptionPrice', 35)
            ->assertJsonPath('data.trialDays', 14)
            ->assertJsonPath('data.waPhoneId', '12345');

        $this->assertDatabaseHas('platform_settings', [
            'id' => 1,
            'subscription_price' => 35,
            'trial_days' => 14,
        ]);
    }

    public function test_merchant_cannot_access_platform_settings(): void
    {
        [, $owner] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/admin/platform-settings')
            ->assertForbidden();
    }

    public function test_user_can_change_password(): void
    {
        $admin = $this->platformAdmin();
        $admin->update(['password' => 'oldpassword123']);
        $token = $admin->createToken('old')->plainTextToken;

        $this->withToken($token)->patchJson('/api/v1/auth/password', [
            'current_password' => 'oldpassword123',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk()
            ->assertJsonStructure(['token', 'password_changed_at']);

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('newpassword123', $admin->fresh()->password)
        );
    }
}
