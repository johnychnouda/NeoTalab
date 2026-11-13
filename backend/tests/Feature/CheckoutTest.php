<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\CheckWhishTransfer;
use App\Jobs\NotifyCustomerOfOrderStatus;
use App\Jobs\NotifyShopOfOrder;
use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopHour;
use App\Services\AI\Providers\OpenAiCompatibleChatProvider;
use App\Services\OrderService;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\Conversation\CartStatus;
use App\Support\Conversation\ConversationStatus;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, \Tests\Concerns\ShopPhone;

    private Merchant $merchant;

    private string $token;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Inside the default opening hours (09:00–23:00) whatever time the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config([
            'whatsapp.platform_access_token' => 'test-token',
            'whatsapp.graph_api_base' => 'https://graph.facebook.com',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class, CheckWhishTransfer::class, NotifyShopOfOrder::class]);

        [$this->merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Beirut Bites',
            'currency' => 'USD',
            'timezone' => 'Asia/Beirut',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto'],
        ]);
        $this->token = $this->tokenFor($owner);

        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shawarma', 'price' => 5]);
    }

    private function say(string $text, string $from = '96170123456', string $phoneId = '555'): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => $from, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    private function lastReply(): string
    {
        $sent = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'));

        return $sent->last()['text']['body'] ?? ($sent->last()['interactive']['body']['text'] ?? '');
    }

    private function zone(string $name, float $fee = 2, float $minimum = 0): DeliveryZone
    {
        return DeliveryZone::query()->forceCreate([
            'merchant_id' => $this->merchant->id, 'name' => $name,
            'delivery_fee' => $fee, 'minimum_order' => $minimum, 'is_active' => true,
        ]);
    }

    private function orders()
    {
        return Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id);
    }

    public function test_a_whish_order_waits_for_the_shop_before_payment_instructions(): void
    {
        $this->merchant->update(['settings' => array_merge($this->merchant->settings ?? [], ['whish' => ['number' => '70123456']])]);
        $this->zone('Hamra', 2);

        $this->say('add 2 shawarma');
        $this->say('confirm | hamra | Bliss St, Building 4, 2nd floor | whish | USD');

        $order = $this->orders()->sole();
        $this->assertSame('placed', $order->status, 'Whish orders wait for ✅ before payment details');
        $this->assertNull($order->accepted_at);
        $this->assertStringNotContainsString('catch that', $this->lastReply(), 'no stray "not understood" after the order');
    }

    public function test_customer_places_order_over_whatsapp(): void
    {
        $this->zone('Hamra', 2);

        $this->say('add 2 shawarma');
        $this->say('confirm | hamra | Bliss St, Building 4, 2nd floor | cash');
        $this->assertSame('placed', $this->orders()->sole()->status, 'every order waits for the shop to type ✅');
        $this->shopTypes('96170123456', '✅');

        $order = $this->orders()->with('items')->sole();
        $this->assertSame(1, $order->number);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame(30, $order->eta_mins);
        $this->assertEquals(10.00, (float) $order->subtotal);
        $this->assertEquals(2.00, (float) $order->delivery_fee);
        $this->assertEquals(12.00, (float) $order->total);
        $this->assertSame('Hamra', $order->delivery_zone_name);
        $this->assertSame('Bliss St, Building 4, 2nd floor', $order->delivery_address);
        $this->assertSame('Shawarma', $order->items->sole()->product_name);
        $this->assertSame(2, $order->items->sole()->quantity);

        // afterCommit notifications stay pending inside RefreshDatabase's transaction
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);
        $this->assertStringContainsString('accepted order #1 — preparing now, ready around', $this->lastReply());
        $conversation = Conversation::query()->withoutGlobalScopes()->findOrFail($order->conversation_id);
        $this->assertSame(ConversationStatus::Closed, $conversation->status);
        $this->assertSame(CartStatus::Converted, Cart::query()->withoutGlobalScopes()->where('conversation_id', $conversation->id)->sole()->status);

        $customer = Customer::query()->withoutGlobalScopes()->findOrFail($order->customer_id);
        $this->assertSame(1, $customer->total_orders);
        $this->assertEquals(12.00, (float) $customer->total_spent);
    }

    public function test_next_message_after_ordering_starts_a_fresh_conversation(): void
    {
        $this->say('add 1 shawarma');
        $this->say('confirm | | Bliss St, Building 4');
        $this->say('add 1 shawarma');

        $this->assertSame(2, Conversation::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->count());
    }

    public function test_order_numbers_increase_per_merchant(): void
    {
        $this->say('add 1 shawarma', '96170000001');
        $this->say('confirm | | Address one 1', '96170000001');
        $this->say('add 1 shawarma', '96170000002');
        $this->say('confirm | | Address two 2', '96170000002');

        $this->assertSame([1, 2], $this->orders()->orderBy('number')->pluck('number')->all());
    }

    public function test_order_numbers_restart_after_the_shop_closes_and_opens_again(): void
    {
        $this->say('add 1 shawarma', '96170000001');
        $this->say('confirm | | Address one 1', '96170000001');
        $this->say('add 1 shawarma', '96170000002');
        $this->say('confirm | | Address two 2', '96170000002');
        $this->assertSame([1, 2], $this->orders()->orderBy('created_at')->pluck('number')->all());

        // Next calendar day after close (default hours 09:00–23:00): numbering starts at 1 again.
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00', 'Asia/Beirut'));
        $this->say('add 1 shawarma', '96170000003');
        $this->say('confirm | | Address three 3', '96170000003');

        $this->assertSame(1, $this->orders()->latest('created_at')->value('number'));
        $this->assertSame([1, 2, 1], $this->orders()->orderBy('created_at')->pluck('number')->all());
    }

    public function test_empty_cart_cannot_be_ordered(): void
    {
        $this->say('confirm | | Bliss St, Building 4');

        $this->assertSame(0, $this->orders()->count());
        $this->assertStringContainsString('cart’s empty', $this->lastReply());
    }

    public function test_zone_is_required_when_shop_has_zones(): void
    {
        $this->zone('Hamra');
        $this->zone('Achrafieh');

        $this->say('add 1 shawarma');
        $this->say('confirm | Jounieh | Bliss St, Building 4');

        $this->assertSame(0, $this->orders()->count());
        $this->assertStringContainsString('Hamra, Achrafieh', $this->lastReply());
    }

    public function test_address_is_required(): void
    {
        $this->say('add 1 shawarma');
        $this->say('confirm');

        $this->assertSame(0, $this->orders()->count());
        // "confirm" with items opens the button checkout, which asks for the location pin first.
        $this->assertStringContainsString('location', mb_strtolower($this->lastReply()));
    }

    public function test_zone_minimum_order_is_enforced(): void
    {
        $this->zone('Hamra', 2, 20);

        $this->say('add 1 shawarma');
        $this->say('confirm | Hamra | Bliss St, Building 4');

        $this->assertSame(0, $this->orders()->count());
        $this->assertStringContainsString('Minimum for Hamra is 20.00$', $this->lastReply());
    }

    public function test_checkout_charges_todays_price_not_the_price_when_added(): void
    {
        $this->say('add 2 shawarma'); // 2 × 5.00
        Product::query()->withoutGlobalScopes()->where('name', 'Shawarma')->update(['price' => 6]);

        $this->say('confirm | | Address one 1');

        $order = $this->orders()->with('items')->sole();
        $this->assertEquals(12.00, (float) $order->subtotal);
        $this->assertEquals(6.00, (float) $order->items->first()->unit_price);
    }

    public function test_whatsapp_name_is_saved_and_a_typed_order_remembers_the_address(): void
    {
        $this->zone('Hamra', 2);
        $send = fn (string $text) => app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'contacts' => [['profile' => ['name' => 'Rami Haddad'], 'wa_id' => '96170123456']],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => '96170123456', 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);

        $send('add 2 shawarma');
        $send('confirm | hamra | Bliss St, Building 4, 2nd floor | cash');

        $customer = Customer::query()->withoutGlobalScopes()->where('phone', '+96170123456')->sole();
        $this->assertSame('Rami Haddad', $customer->name);
        $this->assertSame('Bliss St, Building 4, 2nd floor', $customer->addresses[0]['address']);
        $this->assertSame('Hamra', $customer->addresses[0]['zone']);

        $customer->forceFill(['name' => 'Rami (regular)'])->save();
        $send('hi');
        $this->assertSame('Rami (regular)', $customer->fresh()->name, 'a saved name is never overwritten');
    }

    public function test_orders_are_refused_outside_opening_hours(): void
    {
        foreach (range(0, 6) as $day) {
            ShopHour::query()->forceCreate([
                'merchant_id' => $this->merchant->id, 'day_of_week' => $day,
                'opens_at' => '09:00', 'closes_at' => '17:00', 'is_closed' => false,
            ]);
        }

        Carbon::setTestNow(Carbon::parse('2026-10-05 20:00', 'Asia/Beirut'));
        $this->say('add 1 shawarma');
        $this->say('confirm | | Bliss St, Building 4');
        $this->assertSame(0, $this->orders()->count());
        $this->assertStringContainsString('closed right now', $this->lastReply());

        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'Asia/Beirut'));
        $this->say('add 1 shawarma');
        $this->say('confirm | | Bliss St, Building 4');
        $this->assertSame(1, $this->orders()->count());
    }

    public function test_a_shop_that_never_saved_hours_keeps_the_hours_its_dashboard_shows(): void
    {
        $shown = $this->withToken($this->token)->getJson('/api/v1/settings/hours')->assertOk()->json('hours');
        $this->assertCount(7, $shown);
        $this->assertSame([['09:00', '23:00', false]], array_values(array_unique(array_map(
            fn ($h) => [$h['opens_at'], $h['closes_at'], $h['is_closed']], $shown), SORT_REGULAR)));
        $this->assertSame(0, ShopHour::query()->withoutGlobalScopes()->count(), 'nothing saved yet');

        Carbon::setTestNow(Carbon::parse('2026-10-06 03:00', 'Asia/Beirut'));
        $this->say('add 1 shawarma');
        $this->say('confirm | | Bliss St, Building 4');
        $this->assertSame(0, $this->orders()->count(), 'not open 24/7');
        $this->assertStringContainsString('closed right now', $this->lastReply());

        Carbon::setTestNow(Carbon::parse('2026-10-06 22:30', 'Asia/Beirut'));
        $this->say('add 1 shawarma');
        $this->say('confirm | | Bliss St, Building 4');
        $this->assertSame(1, $this->orders()->count());
    }

    public function test_overnight_hours_accept_orders_after_midnight(): void
    {
        foreach (range(0, 6) as $day) {
            ShopHour::query()->forceCreate([
                'merchant_id' => $this->merchant->id, 'day_of_week' => $day,
                'opens_at' => '18:00', 'closes_at' => '02:00', 'is_closed' => false,
            ]);
        }

        Carbon::setTestNow(Carbon::parse('2026-10-06 01:30', 'Asia/Beirut'));
        $this->say('add 1 shawarma');
        $this->say('confirm | | Bliss St, Building 4');

        $this->assertSame(1, $this->orders()->count());
    }

    public function test_cancelling_notifies_customer_and_reverses_totals(): void
    {
        $this->say('add 1 shawarma');
        $this->say('confirm | | Bliss St, Building 4');
        $this->shopTypes('96170123456', '✅');
        $order = $this->orders()->sole();

        $this->cancelFromShop($order, 'Out of bread');
        $this->assertSame('Order #1 was cancelled. Reason: Out of bread', $this->lastReply());

        $customer = Customer::query()->withoutGlobalScopes()->findOrFail($order->customer_id);
        $this->assertSame(0, $customer->total_orders);
        $this->assertEquals(0.0, (float) $customer->total_spent);
    }

    public function test_status_change_without_whatsapp_connected_still_succeeds(): void
    {
        $this->say('add 1 shawarma');
        $this->say('confirm | | Bliss St, Building 4');
        $this->shopTypes('96170123456', '✅');
        $order = $this->orders()->sole();
        $sentBefore = count(Http::recorded());

        $this->merchant->update(['settings' => ['mode' => 'auto']]);

        $this->cancelFromShop($order);
        $this->assertCount($sentBefore, Http::recorded());
    }

    private function cancelFromShop(Order $order, ?string $reason = null): void
    {
        app(CurrentTenant::class)->set($this->merchant);
        try {
            app(OrderService::class)->transition($this->merchant, $order->id, 'cancel', ['reason' => $reason]);
        } finally {
            app(CurrentTenant::class)->forget();
        }
    }

    public function test_openai_payload_keeps_the_clock_after_the_catalog_so_the_prefix_is_cached(): void
    {
        config(['ai.driver' => 'openai', 'ai.openai.api_key' => 'sk-test']);
        Http::fake(['api.openai.com/*' => Http::response(['model' => 'gpt-4o-mini', 'usage' => [], 'choices' => [['message' => ['content' => json_encode([
            'assistant_message' => 'Hi!', 'intent' => 'greeting', 'recommended_action' => 'none',
        ])]]]])]);

        $payloads = [];
        foreach (['10:00', '10:07'] as $time) {
            Carbon::setTestNow(Carbon::parse("2026-10-18 {$time}", 'Asia/Beirut'));
            $this->say('hello');
        }
        Carbon::setTestNow();

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $payloads[] = $request['messages'][1]['content'];
            }
        }
        $this->assertCount(2, $payloads);

        $data = json_decode($payloads[0], true);
        $this->assertSame(['shop', 'customer', 'catalog', 'now'], array_slice(array_keys($data), 0, 4));
        $this->assertArrayNotHasKey('now', $data['shop']);
        $this->assertStringStartsWith('2026-10-18 10:00', $data['now']);

        // Everything up to the clock is identical between turns minutes apart.
        $prefix = fn (string $p) => substr($p, 0, strpos($p, '"now"'));
        $this->assertSame($prefix($payloads[0]), $prefix($payloads[1]));
        $this->assertNotSame($payloads[0], $payloads[1]);
    }

    public function test_openai_sees_short_ids_and_its_answer_maps_back_to_the_real_item(): void
    {
        config(['ai.driver' => 'openai', 'ai.openai.api_key' => 'sk-test']);
        $fries = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Fries', 'price' => 2.5]);

        Http::fake(['api.openai.com/*' => function (Request $request) {
            $catalog = json_decode($request['messages'][1]['content'], true)['catalog'];
            $short = collect($catalog)->firstWhere('name', 'Fries')['id'];

            return Http::response(['model' => 'gpt-4o-mini', 'usage' => [], 'choices' => [['message' => ['content' => json_encode([
                'assistant_message' => 'Added!', 'intent' => 'order', 'recommended_action' => 'none',
                'cart_actions' => [['action' => 'add', 'product_id' => $short, 'variant_id' => null, 'cart_item_id' => null, 'quantity' => 2, 'modifier_ids' => [], 'notes' => null]],
            ])]]]]);
        }]);

        $this->say('2 fries');

        $payload = collect(Http::recorded())->map(fn ($r) => $r[0])->first(fn ($r) => str_contains($r->url(), 'api.openai.com'))['messages'][1]['content'];
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-/', $payload, 'no UUIDs reach the model');
        $this->assertMatchesRegularExpression('/"id":"i\d+"/', $payload);

        $item = Cart::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->latest()->first()->items()->first();
        $this->assertSame($fries->id, $item->product_id);
        $this->assertSame(2, (int) $item->quantity);
    }

    /** The OpenAI fake answers every turn with this output (null fields filled in). */
    private function aiAnswers(array $output): void
    {
        config(['ai.driver' => 'openai', 'ai.openai.api_key' => 'sk-test']);
        Http::fake(['api.openai.com/*' => function (Request $request) use ($output) {
            $catalog = json_decode($request['messages'][1]['content'], true)['catalog'];
            $output = json_decode(str_replace('{shawarma}', collect($catalog)->firstWhere('name', 'Shawarma')['id'], json_encode($output)), true);

            return Http::response(['model' => 'gpt-6-luna', 'usage' => [], 'choices' => [['message' => ['content' => json_encode($output + [
                'intent' => 'order', 'cart_actions' => [], 'checkout' => null, 'appointment' => null,
            ])]]]]);
        }]);
    }

    public function test_a_typed_address_skips_the_delivery_or_pickup_question(): void
    {
        $this->merchant->forceFill(['settings' => array_merge($this->merchant->settings, ['fulfillment' => 'both'])])->save();
        $this->aiAnswers([
            'assistant_message' => 'Got it!', 'recommended_action' => 'checkout',
            'cart_actions' => [['action' => 'add', 'product_id' => '{shawarma}', 'variant_id' => null, 'cart_item_id' => null, 'quantity' => 1, 'modifier_ids' => [], 'notes' => null]],
            'checkout' => ['fulfillment' => null, 'delivery_zone' => null, 'delivery_address' => 'Sassine street, bldg Rizk, 3rd floor', 'payment_method' => null, 'payment_currency' => null, 'cash_main' => null, 'notes' => null],
        ]);

        $this->say('1 shawarma to Sassine street, bldg Rizk, 3rd floor, that is all');

        $reply = $this->lastReply();
        $this->assertStringNotContainsString('How would you like to get your order', $reply);
        $this->assertStringContainsString('location', $reply, 'the delivery question is skipped, but the location pin is still asked for');

        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => '96170123456', 'type' => 'location', 'location' => ['latitude' => 33.8938, 'longitude' => 35.5018]]],
        ]]]]]]);
        $this->assertStringContainsString('Sassine street, bldg Rizk', $this->lastReply(), 'then straight to the receipt, the typed address is the note');
    }

    public function test_buttons_after_the_reply_use_the_customers_language_not_the_shops(): void
    {
        $this->merchant->forceFill(['locale' => 'fr'])->save();
        $this->aiAnswers([
            'assistant_message' => 'Added 1 shawarma!', 'recommended_action' => 'checkout',
            'conversation_state' => ['phase' => 'checkout', 'language' => 'en'],
            'cart_actions' => [['action' => 'add', 'product_id' => '{shawarma}', 'variant_id' => null, 'cart_item_id' => null, 'quantity' => 1, 'modifier_ids' => [], 'notes' => null]],
        ]);

        $this->say('1 shawarma please, that is all');

        $this->assertStringContainsString('Share your location', $this->lastReply(), 'English, like the customer');
    }

    public function test_an_answer_in_another_language_is_asked_for_again_in_the_customers(): void
    {
        config(['ai.driver' => 'openai', 'ai.openai.api_key' => 'sk-test']);
        $answers = ['أهلاً، كيف يمكنني مساعدتك اليوم؟', 'Hello! How can I help you today?'];
        $asked = [];
        Http::fake(['api.openai.com/*' => function (Request $request) use (&$answers, &$asked) {
            $asked[] = json_decode($request['messages'][1]['content'], true)['reply_language'] ?? null;

            return Http::response(['model' => 'gpt-test', 'usage' => [], 'choices' => [['message' => ['content' => json_encode([
                'assistant_message' => array_shift($answers), 'recommended_action' => 'none', 'intent' => 'greeting',
                'cart_actions' => [], 'checkout' => null, 'appointment' => null,
            ])]]]]);
        }, 'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $this->say('hello, what do you have today?');

        $this->assertSame([null, 'en'], $asked, 'asked again, in English');
        $this->assertStringContainsString('Hello! How can I help', $this->lastReply());
    }

    public function test_an_empty_answer_from_the_assistant_still_gets_the_customer_a_reply(): void
    {
        $this->aiAnswers(['assistant_message' => '', 'recommended_action' => 'none']);

        $this->say('hmm');

        $this->assertStringContainsString("didn't catch that", $this->lastReply());
    }

    public function test_openai_schema_satisfies_strict_mode(): void
    {
        $schema = (new ReflectionMethod(OpenAiCompatibleChatProvider::class, 'responseSchema'))
            ->invoke(app(OpenAiCompatibleChatProvider::class));

        $walk = function (array $node, string $path) use (&$walk) {
            foreach ($node['anyOf'] ?? [] as $i => $alt) {
                $walk($alt, "{$path}.anyOf[{$i}]");
            }
            $types = (array) ($node['type'] ?? []);
            if (in_array('object', $types, true)) {
                $this->assertFalse($node['additionalProperties'] ?? true, "{$path}: additionalProperties must be false");
                $keys = array_keys($node['properties'] ?? []);
                $this->assertEqualsCanonicalizing($keys, $node['required'] ?? [], "{$path}: every property must be required");
                foreach ($node['properties'] ?? [] as $key => $child) {
                    $walk($child, "{$path}.{$key}");
                }
            }
            if (isset($node['items'])) {
                $walk($node['items'], "{$path}[]");
            }
        };

        $walk($schema, '$');
    }
}
