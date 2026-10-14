<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\MerchantWhatsAppCredentials;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            'graph.facebook.com/*/987654321012345/register' => Http::response(['success' => true], 200),
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

        // A new number is registered for Cloud API messaging with a PIN that is kept (encrypted).
        $pin = Crypt::decryptString($merchant->settings['bot']['registration_pin']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '987654321012345/register')
            && $r['messaging_product'] === 'whatsapp' && $r['pin'] === $pin);
    }

    public function test_a_number_meta_cannot_register_is_reported(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAX_embedded_token'], 200),
            'graph.facebook.com/*/123456789/subscribed_apps' => Http::response(['success' => true], 200),
            'graph.facebook.com/*/987654321012345/register' => Http::response(['error' => ['message' => 'Phone number not verified']], 400),
        ]);
        [$merchant] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($this->platformAdmin()))
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/whatsapp/embedded-signup", [
                'code' => 'oauth-code-from-meta', 'phoneNumberId' => '987654321012345', 'wabaId' => '123456789',
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['Meta could not register this number: Phone number not verified']);
    }

    public function test_business_app_coexistence_starts_both_meta_syncs(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAX_embedded_token'], 200),
            'graph.facebook.com/*/123456789/subscribed_apps' => Http::response(['success' => true], 200),
            'graph.facebook.com/*/987654321012345/smb_app_data' => Http::response(['request_id' => 'req-1'], 200),
            'graph.facebook.com/*/987654321012345*' => Http::response(['display_phone_number' => '+96171112233'], 200),
        ]);

        [$merchant] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($this->platformAdmin()))
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/whatsapp/embedded-signup", [
                'code' => 'oauth-code-from-meta',
                'phoneNumberId' => '987654321012345',
                'wabaId' => '123456789',
                'coexistence' => true,
            ])
            ->assertOk();

        $syncs = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn ($r) => str_ends_with($r->url(), '/smb_app_data'))
            ->map(fn ($r) => $r['sync_type'])->values()->all();
        $this->assertSame(['smb_app_state_sync', 'history'], $syncs);
        $this->assertTrue($merchant->fresh()->settings['bot']['coexistence']);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/register'));
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

    public function test_neotalabs_own_number_connects_by_qr_and_sends_with_its_own_token(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAX_platform_token'], 200),
            'graph.facebook.com/*/555000/subscribed_apps' => Http::response(['success' => true], 200),
            'graph.facebook.com/*/777000/smb_app_data' => Http::response(['success' => true], 200),
            'graph.facebook.com/*/555000/message_templates' => Http::response(['id' => 'tpl', 'status' => 'PENDING'], 200),
            'graph.facebook.com/*/777000/messages' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
            'graph.facebook.com/*/777000*' => Http::response(['display_phone_number' => '+961 70 000 202'], 200),
        ]);
        config(['whatsapp.platform_access_token' => 'shared-shop-token']);

        $this->withToken($this->tokenFor($this->platformAdmin()))
            ->postJson('/api/v1/admin/whatsapp/platform/embedded-signup', [
                'code' => 'oauth-code', 'phoneNumberId' => '777000', 'wabaId' => '555000', 'coexistence' => true,
            ])
            ->assertOk()
            ->assertJsonPath('settings.waPhoneId', '777000')
            ->assertJsonPath('settings.waConnectedByQr', true)
            ->assertJsonPath('settings.waDisplayPhone', '+961 70 000 202');

        // Coexistence: the number keeps the WhatsApp Business app, so it is synced, not registered.
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '777000/register'));
        // The alert template is submitted on the new account.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '555000/message_templates') && $r['name'] === 'system_alert' && $r['allow_category_change'] === true);

        // Messages from NeoTalab's number use its own token; shops keep the shared one.
        app(WhatsAppService::class)->sendPlatform('+96170000101', ['type' => 'text', 'text' => 'hi']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '777000/messages') && $r->header('Authorization')[0] === 'Bearer EAAX_platform_token');
        $this->assertSame('shared-shop-token', MerchantWhatsAppCredentials::platformAccessToken());
    }

    public function test_replies_to_neotalabs_own_number_are_not_reported_as_problems(): void
    {
        $platform = PlatformSetting::singleton();
        $platform->settings = ['wa_phone_id' => '777000'];
        $platform->save();
        Log::spy();

        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '777000'],
            'messages' => [['id' => 'wamid.R1', 'from' => '96170000101', 'type' => 'text', 'text' => ['body' => 'thanks!']]],
        ]]]]]]);

        Log::shouldNotHaveReceived('warning');
    }
}
