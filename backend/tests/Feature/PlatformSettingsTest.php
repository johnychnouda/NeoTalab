<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Support\StoredSecret;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            ->assertJsonPath('data.subscriptionPrice', 30)
            ->assertJsonPath('data.trialDays', 3);

        $this->withToken($token)->patchJson('/api/v1/admin/platform-settings', [
            'subscription_price' => 35,
            'trial_days' => 21,
            'wa_phone_id' => '12345',
        ])->assertOk()
            ->assertJsonPath('data.subscriptionPrice', 35)
            ->assertJsonPath('data.trialDays', 21)
            ->assertJsonPath('data.waPhoneId', '12345');

        $this->assertDatabaseHas('platform_settings', [
            'id' => 1,
            'subscription_price' => 35,
            'trial_days' => 21,
        ]);
    }

    public function test_masked_whatsapp_token_is_not_overwritten_on_save(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->tokenFor($admin);

        $this->withToken($token)->patchJson('/api/v1/admin/platform-settings', [
            'wa_access_token' => 'EAAX_real_token',
        ])->assertOk();

        $this->withToken($token)->patchJson('/api/v1/admin/platform-settings', [
            'wa_access_token' => '••••••••',
            'wa_phone_id' => '99999',
        ])->assertOk()
            ->assertJsonPath('data.waPhoneId', '99999')
            ->assertJsonPath('data.waTokenConfigured', true);

        $settings = PlatformSetting::singleton()->fresh()->settings ?? [];
        $this->assertNotSame('EAAX_real_token', $settings['wa_access_token'] ?? null); // stored encrypted
        $this->assertSame('EAAX_real_token', StoredSecret::open($settings['wa_access_token'] ?? null));
    }

    public function test_platform_admin_can_reveal_whatsapp_access_token(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->tokenFor($admin);

        $this->withToken($token)->patchJson('/api/v1/admin/platform-settings', [
            'wa_access_token' => 'EAAX_reveal_me',
        ])->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/admin/platform-settings/whatsapp-access-token')
            ->assertOk()
            ->assertJsonPath('wa_access_token', 'EAAX_reveal_me');
    }

    public function test_merchant_cannot_reveal_whatsapp_access_token(): void
    {
        [, $owner] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/admin/platform-settings/whatsapp-access-token')
            ->assertForbidden();
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
            Hash::check('newpassword123', $admin->fresh()->password)
        );
    }

    public function test_owner_can_change_login_email(): void
    {
        $admin = $this->platformAdmin();
        $admin->update(['password' => 'oldpassword123']);
        $old = $admin->createToken('old')->plainTextToken;
        $other = $admin->createToken('other')->plainTextToken;
        [, $shopUser] = $this->merchantWithUser();

        $this->withToken($old)->patchJson('/api/v1/admin/account/email', ['email' => 'buyer@example.com', 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->withToken($old)->patchJson('/api/v1/admin/account/email', ['email' => $shopUser->email, 'current_password' => 'oldpassword123'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $token = $this->withToken($old)->patchJson('/api/v1/admin/account/email', ['email' => ' Buyer@Example.com ', 'current_password' => 'oldpassword123'])
            ->assertOk()->assertJsonPath('email', 'buyer@example.com')->json('token');

        $this->assertSame('buyer@example.com', $admin->fresh()->email);
        $this->assertSame(1, $admin->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'buyer@example.com', 'password' => 'oldpassword123'])->assertOk();
    }

    public function test_shop_users_cannot_use_the_owner_email_change(): void
    {
        [, $shopUser] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($shopUser))
            ->patchJson('/api/v1/admin/account/email', ['email' => 'x@example.com', 'current_password' => 'password'])
            ->assertForbidden();
    }
}
