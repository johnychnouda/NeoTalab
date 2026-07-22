<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WhatsAppEmbeddedSignupTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config([
            'whatsapp.meta_app_id' => 'meta-app-id',
            'whatsapp.app_secret' => 'meta-app-secret',
            'whatsapp.embedded_signup_config_id' => 'embedded-config-id',
        ]);
    }

    public function test_embedded_signup_config_returns_app_credentials(): void
    {
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/whatsapp/embedded-signup/config')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('appId', 'meta-app-id')
            ->assertJsonPath('configId', 'embedded-config-id');
    }

    public function test_embedded_signup_connect_exchanges_code_and_stores_merchant_bot(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'EAAX_embedded_token',
            ], 200),
            'graph.facebook.com/*/123456789/subscribed_apps' => Http::response(['success' => true], 200),
            'graph.facebook.com/*/987654321012345*' => Http::response([
                'display_phone_number' => '+96171112233',
                'verified_name' => 'Test Shop',
            ], 200),
        ]);

        [$merchant] = $this->merchantWithUser();
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/whatsapp/embedded-signup", [
                'code' => 'oauth-code-from-meta',
                'phoneNumberId' => '987654321012345',
                'wabaId' => '123456789',
            ])
            ->assertOk()
            ->assertJsonPath('merchant.bot_status', 'active')
            ->assertJsonPath('merchant.bot_connected_via', 'embedded_signup')
            ->assertJsonPath('merchant.bot_phone_id', '987654321012345');

        $merchant->refresh();
        $this->assertSame('embedded_signup', $merchant->settings['bot']['connected_via']);
        $this->assertSame('987654321012345', $merchant->settings['bot']['phone_id']);
        $this->assertSame('EAAX_embedded_token', $merchant->settings['bot']['access_token']);
    }

    public function test_embedded_signup_rejects_incomplete_payload(): void
    {
        [$merchant] = $this->merchantWithUser();
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/whatsapp/embedded-signup", [
                'code' => 'oauth-code',
                'phoneNumberId' => '123',
            ])
            ->assertUnprocessable();
    }
}
