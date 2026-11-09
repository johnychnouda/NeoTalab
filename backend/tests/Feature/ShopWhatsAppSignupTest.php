<?php

namespace Tests\Feature;

use App\Jobs\SubmitShopTemplates;
use App\Models\SystemAlert;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** A shop owner connects the shop's own WhatsApp number from the dashboard, with the Facebook window. */
class ShopWhatsAppSignupTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const PAYLOAD = ['code' => 'oauth-code-from-meta', 'phoneNumberId' => '987654321012345', 'wabaId' => '123456789'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'whatsapp.meta_app_id' => 'meta-app-id',
            'whatsapp.app_secret' => 'meta-app-secret',
            'whatsapp.embedded_signup_config_id' => 'embedded-config-id',
        ]);
        Queue::fake();
    }

    private function metaAnswers(?string $registerError = null): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAAX_shop_token'], 200),
            'graph.facebook.com/*/123456789/subscribed_apps' => Http::response(['success' => true], 200),
            'graph.facebook.com/*/987654321012345/register' => $registerError
                ? Http::response(['error' => ['message' => $registerError]], 400)
                : Http::response(['success' => true], 200),
            'graph.facebook.com/*/987654321012345*' => Http::response(['display_phone_number' => '+96132070780', 'verified_name' => 'Shop'], 200),
        ]);
    }

    public function test_the_shop_owner_connects_the_shops_own_number(): void
    {
        $this->metaAnswers();
        [$merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER);

        $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/settings/whatsapp/embedded-signup', self::PAYLOAD)
            ->assertOk()
            ->assertJsonPath('setup.whatsapp_connected', true);

        $this->assertSame('987654321012345', $merchant->fresh()->settings['bot']['phone_id']);
        $this->assertNotNull(MerchantWhatsAppCredentials::resolve($merchant->fresh()));
        Queue::assertPushed(SubmitShopTemplates::class, fn ($job) => $job->merchantId === $merchant->id);
    }

    public function test_the_config_for_the_facebook_window_is_given_to_the_shop(): void
    {
        [, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER);

        $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/settings/whatsapp/embedded-signup/config')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('appId', 'meta-app-id')
            ->assertJsonMissingPath('app_secret');
    }

    public function test_a_shop_that_is_already_connected_cannot_switch_its_own_number(): void
    {
        $this->metaAnswers();
        [$merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        $token = $this->tokenFor($owner);
        $this->withToken($token)->postJson('/api/v1/settings/whatsapp/embedded-signup', self::PAYLOAD)->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/settings/whatsapp/embedded-signup', ['phoneNumberId' => '555000111222333'] + self::PAYLOAD)
            ->assertUnprocessable();

        $this->assertSame('987654321012345', $merchant->fresh()->settings['bot']['phone_id']);
    }

    public function test_a_number_another_shop_already_uses_is_refused(): void
    {
        $this->metaAnswers();
        [, $first] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        [$second, $secondOwner] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        $this->withToken($this->tokenFor($first))->postJson('/api/v1/settings/whatsapp/embedded-signup', self::PAYLOAD)->assertOk();

        $this->withToken($this->tokenFor($secondOwner))
            ->postJson('/api/v1/settings/whatsapp/embedded-signup', self::PAYLOAD)
            ->assertUnprocessable();

        $this->assertNull(($second->fresh()->settings['bot'] ?? [])['phone_id'] ?? null);
    }

    public function test_a_signup_meta_refuses_tells_the_shop_and_leaves_a_record(): void
    {
        $this->metaAnswers('Two-step verification PIN mismatch');
        [$merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER);

        $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/settings/whatsapp/embedded-signup', self::PAYLOAD)
            ->assertUnprocessable();

        $alert = SystemAlert::query()->sole();
        $this->assertStringContainsString('WhatsApp signup did not finish', $alert->message);
        $this->assertSame($merchant->id, $alert->merchant_id);
    }
}
