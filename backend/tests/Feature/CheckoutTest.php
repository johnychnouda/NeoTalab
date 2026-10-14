<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    private string $token;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

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

    public function test_customer_places_order_over_whatsapp(): void
    {
        $this->zone('Hamra', 2);

        $this->say('add 2 shawarma');
        $this->say('confirm | hamra | Bliss St, Building 4, 2nd floor | cash');

        $order = $this->orders()->with('items')->sole();
        $this->assertSame(1, $order->number);
        $this->assertSame('confirmed', $order->status, 'the bot accepts orders itself');
        $this->assertSame(30, $order->eta_mins);
        $this->assertEquals(10.00, (float) $order->subtotal);
        $this->assertEquals(2.00, (float) $order->delivery_fee);
        $this->assertEquals(12.00, (float) $order->total);
        $this->assertSame('Hamra', $order->delivery_zone_name);
        $this->assertSame('Bliss St, Building 4, 2nd floor', $order->delivery_address);
        $this->assertSame('Shawarma', $order->items->sole()->product_name);
        $this->assertSame(2, $order->items->sole()->quantity);

        $this->assertStringContainsString('Order #1 confirmed', $this->lastReply());
        $this->assertStringContainsString('confirmed — arriving around', $this->lastReply());
        $this->assertStringContainsString('12.00$', $this->lastReply());

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

    public function test_empty_cart_cannot_be_ordered(): void
    {
        $this->say('confirm | | Bliss St, Building 4');

        $this->assertSame(0, $this->orders()->count());
        $this->assertStringContainsString('cart is empty', $this->lastReply());
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
        $this->assertStringContainsString('location pin', $this->lastReply());
    }

    public function test_zone_minimum_order_is_enforced(): void
    {
        $this->zone('Hamra', 2, 20);

        $this->say('add 1 shawarma');
        $this->say('confirm | Hamra | Bliss St, Building 4');

        $this->assertSame(0, $this->orders()->count());
        $this->assertStringContainsString('minimum order for Hamra is 20.00$', $this->lastReply());
    }

    public function test_out_of_stock_item_blocks_checkout_and_stock_is_decremented(): void
    {
        $tracked = Product::factory()->forMerchant($this->merchant)->tracked(3)->create(['name' => 'Falafel', 'price' => 3]);

        $this->say('add 2 falafel', '96170000001');
        $this->say('add 2 falafel', '96170000002');
        $this->say('confirm | | Address one 1', '96170000001');
        $this->assertSame(1, $tracked->fresh()->stock_quantity);

        $this->say('confirm | | Address two 2', '96170000002');
        $this->assertSame(1, $this->orders()->count());
        $this->assertStringContainsString('"Falafel" is no longer available', $this->lastReply());
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
        $order = $this->orders()->sole();

        $this->cancelFromShop($order, 'Out of bread');
        $this->assertSame('Your order #1 was cancelled. Reason: Out of bread', $this->lastReply());

        $customer = Customer::query()->withoutGlobalScopes()->findOrFail($order->customer_id);
        $this->assertSame(0, $customer->total_orders);
        $this->assertEquals(0.0, (float) $customer->total_spent);
    }

    public function test_status_change_without_whatsapp_connected_still_succeeds(): void
    {
        $this->say('add 1 shawarma');
        $this->say('confirm | | Bliss St, Building 4');
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
