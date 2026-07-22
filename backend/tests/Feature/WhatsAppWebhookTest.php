<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.verify_signature' => false]);
    }

    public function test_meta_webhook_verification(): void
    {
        config(['whatsapp.verify_token' => 'test-verify-token']);

        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    }

    public function test_webhook_rejects_bad_verify_token(): void
    {
        $this->getJson('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')
            ->assertForbidden();
    }

    public function test_inbound_webhook_queues_processing(): void
    {
        Queue::fake();

        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'settings' => [
                'bot' => ['phone_id' => '999888777'],
                'subscription_status' => 'trial',
            ],
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '999888777'],
                        'messages' => [[
                            'id' => 'wamid.TEST123',
                            'from' => '96170123456',
                            'type' => 'text',
                            'text' => ['body' => 'menu'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload)
            ->assertOk()
            ->assertJson(['ok' => true]);

        Queue::assertPushed(\App\Jobs\ProcessWhatsAppWebhook::class);
    }

    public function test_inbound_message_triggers_ai_reply(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.OUT1']],
            ], 200),
            '*/999888777*' => Http::response([
                'display_phone_number' => '+96170000000',
                'verified_name' => 'Test Shop',
            ], 200),
        ]);

        config(['whatsapp.platform_access_token' => 'test-token']);

        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'settings' => [
                'bot' => ['phone_id' => '999888777'],
                'mode' => 'auto',
                'subscription_status' => 'trial',
            ],
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '999888777'],
                        'messages' => [[
                            'id' => 'wamid.TEST456',
                            'from' => '96170123456',
                            'type' => 'text',
                            'text' => ['body' => 'hello'],
                        ]],
                    ],
                ]],
            ]],
        ];

        app(WhatsAppInboundService::class)->handle($payload);

        $this->assertDatabaseHas('whatsapp_messages', [
            'merchant_id' => $merchant->id,
            'wa_message_id' => 'wamid.TEST456',
            'direction' => 'inbound',
        ]);

        Http::assertSentCount(1);
    }

    public function test_admin_bot_test_calls_meta_api(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'display_phone_number' => '+96171112233',
                'verified_name' => 'Joe Snacks',
            ], 200),
        ]);

        [$merchant] = $this->merchantWithUser();
        $admin = $this->platformAdmin();

        $merchant->forceFill([
            'settings' => [
                'bot' => [
                    'phone_id' => '123456789',
                    'access_token' => 'EAAX_test_token',
                ],
            ],
        ])->save();

        $this->withToken($this->tokenFor($admin))
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/bot/test")
            ->assertOk()
            ->assertJsonPath('success', true);

        $merchant->refresh();
        $this->assertSame('active', $merchant->settings['bot']['status']);
    }
}
