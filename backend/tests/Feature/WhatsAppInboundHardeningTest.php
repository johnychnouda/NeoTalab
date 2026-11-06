<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\WhatsAppMessage;
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
            ->map(fn (Request $r) => $r['text']['body'] ?? $r['interactive']['body']['text'] ?? '')
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

    public function test_a_message_whose_worker_died_is_answered_on_retry_without_running_it_again(): void
    {
        $merchant = $this->merchant('111');
        $payload = ['entry' => [['changes' => [$this->change('111', [$this->message('wamid.DEAD')])]]]];
        // The first attempt recorded the message, then its worker was killed: no handled_at, five minutes ago.
        WhatsAppMessage::query()->forceCreate([
            'merchant_id' => $merchant->id, 'wa_message_id' => 'wamid.DEAD', 'direction' => 'inbound', 'from_number' => '+96170123456',
            'to_number' => '111', 'message_type' => 'text', 'payload' => ['id' => 'wamid.DEAD'], 'created_at' => now()->subMinutes(5),
        ]);

        app(WhatsAppInboundService::class)->handle($payload);
        app(WhatsAppInboundService::class)->handle($payload);

        $sent = $this->sentTexts();
        $this->assertCount(1, $sent, 'one honest reply, not one per retry');
        $this->assertStringContainsString('interrupted', $sent[0]);
    }

    public function test_an_interrupted_message_shows_the_cart_so_an_item_already_added_is_not_asked_for_again(): void
    {
        $merchant = $this->merchant('111');
        // The first attempt added the item, then its worker died before replying (seen at scale, s46 with 800 customers).
        $product = Product::factory()->forMerchant($merchant)->create(['name' => 'Croissant', 'price' => 2.5]);
        $customer = Customer::factory()->forMerchant($merchant)->create(['phone' => '+96170123456', 'locale' => 'en']);
        $conversation = Conversation::factory()->forMerchant($merchant)->create(['customer_id' => $customer->id]);
        $cart = Cart::factory()->forConversation($conversation)->create(['subtotal' => 2.5]);
        CartItem::factory()->forCart($cart)->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 2.5, 'line_total' => 2.5]);
        WhatsAppMessage::query()->forceCreate([
            'merchant_id' => $merchant->id, 'wa_message_id' => 'wamid.HALF', 'direction' => 'inbound', 'from_number' => '+96170123456',
            'to_number' => '111', 'message_type' => 'text', 'payload' => ['id' => 'wamid.HALF'], 'created_at' => now()->subMinutes(5),
        ]);

        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [$this->change('111', [$this->message('wamid.HALF', 'add 1 Croissant')])]]]]);

        $sent = $this->sentTexts();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Your cart:', $sent[0]);
        $this->assertStringContainsString('1× Croissant', $sent[0]);
    }

    public function test_a_message_already_answered_before_its_worker_died_gets_no_second_reply(): void
    {
        $merchant = $this->merchant('111');
        // The worker sent its reply, then died before marking the message handled (seen at scale, s46 with 800 customers).
        WhatsAppMessage::query()->forceCreate([
            'merchant_id' => $merchant->id, 'wa_message_id' => 'wamid.SENT', 'direction' => 'inbound', 'from_number' => '+96170123456',
            'to_number' => '111', 'message_type' => 'text', 'payload' => ['id' => 'wamid.SENT'], 'created_at' => now()->subMinutes(5),
        ]);
        WhatsAppMessage::query()->forceCreate([
            'merchant_id' => $merchant->id, 'wa_message_id' => 'wamid.REPLY', 'direction' => 'outbound', 'from_number' => '111',
            'to_number' => '+96170123456', 'message_type' => 'text', 'payload' => ['text' => ['body' => 'Added']], 'created_at' => now()->subMinutes(5)->addSecond(),
        ]);

        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [$this->change('111', [$this->message('wamid.SENT')])]]]]);

        $this->assertSame([], $this->sentTexts());
    }

    public function test_a_message_from_neotalabs_own_number_gets_no_reply(): void
    {
        // The nightly report may go to the shop's own number: NeoTalab is not a customer to answer.
        $this->merchant('111');
        PlatformSetting::singleton()->update(['settings' => ['wa_phone_id' => '999', 'wa_display_phone' => '+961 70 000 111']]);

        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [$this->change('111', [$this->message('wamid.NT', 'Daily report', '96170000111')])]]]]);

        $this->assertSame([], $this->sentTexts());
        $this->assertSame(0, Customer::query()->withoutGlobalScopes()->where('phone', '+96170000111')->count());
    }

    public function test_a_message_still_being_handled_by_a_live_worker_is_left_alone(): void
    {
        $merchant = $this->merchant('111');
        WhatsAppMessage::query()->forceCreate([
            'merchant_id' => $merchant->id, 'wa_message_id' => 'wamid.SLOW', 'direction' => 'inbound', 'from_number' => '+96170123456',
            'to_number' => '111', 'message_type' => 'text', 'payload' => ['id' => 'wamid.SLOW'], 'created_at' => now()->subSeconds(20),
        ]);

        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [$this->change('111', [$this->message('wamid.SLOW')])]]]]);

        $this->assertSame([], $this->sentTexts());
    }

    public function test_a_handled_message_is_marked_so_a_late_duplicate_stays_silent(): void
    {
        $merchant = $this->merchant('111');
        $payload = ['entry' => [['changes' => [$this->change('111', [$this->message('wamid.DONE')])]]]];
        app(WhatsAppInboundService::class)->handle($payload);

        $this->assertArrayHasKey('handled_at', WhatsAppMessage::query()->where('merchant_id', $merchant->id)->where('wa_message_id', 'wamid.DONE')->first()->payload);

        $this->travel(10)->minutes();
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

    public function test_a_number_flooding_a_shop_stops_getting_replies(): void
    {
        config(['whatsapp.customer_limit_per_10_minutes' => 3]);
        $this->merchant('111');

        foreach (range(1, 5) as $i) {
            app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [$this->change('111', [$this->message("wamid.F{$i}")])]]]]);
        }
        // Another customer of the same shop is not affected.
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [$this->change('111', [$this->message('wamid.OTHER', 'hi', '96170999999')])]]]]);

        $this->assertCount(4, $this->sentTexts());
        $this->assertDatabaseHas('whatsapp_messages', ['wa_message_id' => 'wamid.F5']);
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
