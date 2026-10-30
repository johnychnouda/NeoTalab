<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Support\Roles;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** The owner portal's "Connected" badge asks Meta, instead of only checking that an ID and a token are filled in. */
class PlatformWhatsAppCheckTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlatformSettingsSeeder::class);
        config(['whatsapp.graph_api_base' => 'https://graph.facebook.com', 'whatsapp.graph_api_version' => 'v26.0']);
        $this->token = $this->tokenFor($this->platformAdmin());
    }

    private function configure(): void
    {
        $this->withToken($this->token)->patchJson('/api/v1/admin/platform-settings', [
            'wa_phone_id' => '251311448055371', 'wa_access_token' => 'EAAX_secret_token',
        ])->assertOk();
    }

    private function check(): TestResponse
    {
        return $this->withToken($this->token)->getJson('/api/v1/admin/platform-settings/whatsapp-status');
    }

    public function test_it_reports_connected_when_meta_says_the_number_is_connected(): void
    {
        $this->configure();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => '251311448055371', 'display_phone_number' => '+961 70 207 078', 'verified_name' => 'NeoTalab',
            'status' => 'CONNECTED', 'name_status' => 'APPROVED',
        ], 200)]);

        $this->check()->assertOk()
            ->assertJsonPath('state', 'connected')
            ->assertJsonPath('phone', '+961 70 207 078')
            ->assertJsonPath('name', 'NeoTalab');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/251311448055371') && $r->hasHeader('Authorization', 'Bearer EAAX_secret_token'));
    }

    public function test_it_says_what_is_wrong_when_meta_refuses_the_token(): void
    {
        $this->configure();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Error validating access token: Session has expired', 'code' => 190]], 400)]);

        $response = $this->check()->assertOk()->assertJsonPath('state', 'problem');
        $this->assertStringContainsString('Session has expired', $response->json('message'));
        $this->assertStringNotContainsString('EAAX_secret_token', $response->getContent());
    }

    public function test_a_number_meta_does_not_call_connected_is_a_problem_not_a_green_badge(): void
    {
        $this->configure();
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1', 'display_phone_number' => '+961 70 207 078', 'status' => 'PENDING'], 200)]);

        $this->check()->assertOk()->assertJsonPath('state', 'problem');
    }

    public function test_nothing_is_asked_of_meta_when_no_number_is_saved(): void
    {
        Http::fake();

        $this->check()->assertOk()->assertJsonPath('state', 'not_set');
        Http::assertNothingSent();
    }

    public function test_a_meta_outage_is_reported_as_a_problem(): void
    {
        $this->configure();
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('timeout')]);

        $this->check()->assertOk()->assertJsonPath('state', 'problem');
    }

    public function test_a_shop_owner_cannot_use_it(): void
    {
        [, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER);

        $this->withToken($this->tokenFor($owner))->getJson('/api/v1/admin/platform-settings/whatsapp-status')->assertForbidden();
        $this->assertNull(PlatformSetting::singleton()->settings['wa_phone_id'] ?? null);
    }
}
