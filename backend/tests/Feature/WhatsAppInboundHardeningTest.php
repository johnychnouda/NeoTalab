<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Merchant;
use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\DTO\ConversationTurnInput;
use App\Services\AI\DTO\StructuredTurnOutput;
use App\Services\AI\Providers\FakeChatProvider;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\Conversation\ConversationStatus;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WhatsAppInboundHardeningTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    }

    private function merchant(string $phoneId = '111', array $attrs = []): Merchant
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, array_merge([
            'settings' => ['bot' => ['phone_id' => $phoneId], 'mode' => 'auto'],
        ], $attrs));

        return $merchant;
    }

    private function message(string $id, string $body = 'hello', string $from = '96170123456'): array
    {
        return ['id' => $id, 'from' => $from, 'type' => 'text', 'text' => ['body' => $body]];
    }

    private function change(string $phoneId, array $messages): array
    {
        return ['value' => ['metadata' => ['phone_number_id' => $phoneId], 'messages' => $messages]];
    }

    /** @return list<string> */
    private function sentTexts(): array
    {
        return Http::recorded()
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))
            ->map(fn (Request $r) => $r['text']['body'] ?? '')
            ->values()->all();
    }

    public function test_webhook_rejects_unsigned_payloads_in_production_without_secret(): void
    {
        config(['whatsapp.app_secret' => null, 'whatsapp.verify_signature' => false]);
        $this->app['env'] = 'production';

        $this->postJson('/api/v1/webhooks/whatsapp', ['entry' => []])->assertForbidden();
    }

    public function test_webhook_accepts_correctly_signed_payload_in_production(): void
    {
        config(['whatsapp.app_secret' => 'shh']);
        $this->app['env'] = 'production';
        $body = json_encode(['entry' => []]);

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'shh'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
    }

    public function test_all_batched_entries_and_changes_are_processed(): void
    {
        $a = $this->merchant('111');
        $b = $this->merchant('222');

        app(WhatsAppInboundService::class)->handle(['entry' => [
            ['changes' => [$this->change('111', [$this->message('wamid.A1'), $this->message('wamid.A2', 'hi', '96170000001')])]],
            ['changes' => [$this->change('222', [$this->message('wamid.B1')])]],
        ]]);

        $this->assertDatabaseHas('whatsapp_messages', ['merchant_id' => $a->id, 'wa_message_id' => 'wamid.A1']);
        $this->assertDatabaseHas('whatsapp_messages', ['merchant_id' => $a->id, 'wa_message_id' => 'wamid.A2']);
        $this->assertDatabaseHas('whatsapp_messages', ['merchant_id' => $b->id, 'wa_message_id' => 'wamid.B1']);
        $this->assertCount(3, $this->sentTexts());
        $this->assertFalse(app(CurrentTenant::class)->check(), 'tenant must be unbound after processing');
    }

    public function test_duplicate_delivery_is_processed_once(): void
    {
        $this->merchant('111');
        $payload = ['entry' => [['changes' => [$this->change('111', [$this->message('wamid.DUP')])]]]];

        app(WhatsAppInboundService::class)->handle($payload);
        app(WhatsAppInboundService::class)->handle($payload);

        $this->assertCount(1, $this->sentTexts());
    }

    public function test_blocked_customer_is_ignored_silently(): void
    {
        $merchant = $this->merchant('111');
        Customer::query()->forceCreate(['merchant_id' => $merchant->id, 'phone' => '+96170123456', 'is_blocked' => true]);

        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [$this->change('111', [$this->message('wamid.BLK')])]]]]);

        $this->assertSame([], $this->sentTexts());
    }

    public function test_ai_failure_sends_fallback_message_instead_of_failing_the_job(): void
    {
        $this->merchant('111');
        $this->app->bind(ChatProvider::class, fn () => new class implements ChatProvider
        {
            public function processTurn(ConversationTurnInput $input): StructuredTurnOutput
            {
                throw new RuntimeException('provider down');
            }
        });

        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [$this->change('111', [$this->message('wamid.ERR')])]]]]);

        $this->assertSame(['Sorry, we could not process your message right now. Please try again in a moment.'], $this->sentTexts());
    }

    public function test_idle_conversation_is_closed_and_a_new_one_started(): void
    {
        $merchant = $this->merchant('111');
        $handle = fn (string $id) => app(WhatsAppInboundService::class)
            ->handle(['entry' => [['changes' => [$this->change('111', [$this->message($id)])]]]]);

        $handle('wamid.OLD');
        $first = Conversation::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)->sole();

        $this->travel(25)->hours();
        $handle('wamid.NEW');

        $this->assertSame(ConversationStatus::Closed, $first->fresh()->status);
        $this->assertSame(2, Conversation::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)->count());
    }

    public function test_ai_receives_capped_history_and_shop_context(): void
    {
        config(['ai.conversation.history_turns' => 4]);
        $this->merchant('111', ['name' => 'Beirut Bites', 'currency' => 'USD']);

        $captured = new \stdClass;
        $this->app->bind(ChatProvider::class, fn ($app) => new class($app->make(FakeChatProvider::class), $captured) implements ChatProvider
        {
            public function __construct(private FakeChatProvider $inner, private \stdClass $captured) {}

            public function processTurn(ConversationTurnInput $input): StructuredTurnOutput
            {
                $this->captured->input = $input;

                return $this->inner->processTurn($input);
            }
        });

        for ($i = 1; $i <= 5; $i++) {
            app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [$this->change('111', [$this->message("wamid.H{$i}")])]]]]);
        }

        $this->assertCount(4, $captured->input->history);
        $this->assertSame('Beirut Bites', $captured->input->shop['name']);
        $this->assertSame('USD', $captured->input->shop['currency']);
    }

    public function test_one_chat_is_handled_one_message_at_a_time(): void
    {
        $merchant = $this->merchant('111');
        $key = "wa-chat:{$merchant->id}:+96170123456";
        $seen = new \stdClass;
        $this->app->bind(ChatProvider::class, fn () => new class(app(FakeChatProvider::class), $seen, $key) implements ChatProvider
        {
            public function __construct(private FakeChatProvider $inner, private \stdClass $seen, private string $key) {}

            public function processTurn(ConversationTurnInput $input): StructuredTurnOutput
            {
                // A second worker on the same chat can't get in while this message is handled.
                $this->seen->busy = ! Cache::lock($this->key, 10)->get();

                return $this->inner->processTurn($input);
            }
        });

        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [$this->change('111', [$this->message('wamid.LOCK')])]]]]);

        $this->assertTrue($seen->busy);
        $this->assertTrue(Cache::lock($key, 10)->get(), 'the chat is free again afterwards');
    }
}
