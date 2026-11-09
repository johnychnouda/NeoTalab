<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\CheckoutFlow;
use App\Services\ConversationService;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, \Tests\Concerns\ShopPhone;

    private const CUSTOMER = '96170123456';

    private Merchant $merchant;

    private Product $zaatar;

    private DeliveryZone $jbeil;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Inside the default opening hours (09:00–23:00) whatever time the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token', 'app.frontend_url' => 'https://neotalab.com']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class]);

        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Foren', 'currency' => 'USD', 'timezone' => 'Asia/Beirut',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'lbp_rate' => 89500, 'prep_time_mins' => 25],
        ]);
        $this->zaatar = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Zaatar', 'price' => 2]);
        $this->jbeil = DeliveryZone::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Jbeil', 'delivery_fee' => 1.5, 'minimum_order' => 3, 'is_active' => true]);
        DeliveryZone::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Amchit', 'delivery_fee' => 2, 'minimum_order' => 0, 'is_active' => true]);
    }

    private function inbound(array $message): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [array_merge(['id' => 'wamid.IN'.(++$this->seq), 'from' => self::CUSTOMER], $message)],
        ]]]]]]);
    }

    private function say(string $text): void
    {
        $this->inbound(['type' => 'text', 'text' => ['body' => $text]]);
    }

    private function tap(string $id, string $title = 'x'): void
    {
        $this->inbound(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]]]);
    }

    private function pickRow(string $id): void
    {
        $this->inbound(['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => 'x']]]);
    }

    /** @return array<string, mixed> */
    private function last(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))->last()->data();
    }

    private function buttonIds(): array
    {
        $m = $this->last();

        return array_map(fn ($b) => $b['reply']['id'], $m['interactive']['action']['buttons'] ?? []);
    }

    /** Orders by writing to the assistant: the item and quantity, then "done". */
    private function menuOrder(int $qty = 2): void
    {
        $this->say("add {$qty} zaatar");
        $this->say('done');
    }

    private function order(): Order
    {
        return Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->latest('number')->firstOrFail();
    }

    /** Zones drawn on the map: an inner ring around the shop and a wider one. */
    private function drawRings(): void
    {
        DeliveryZone::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->delete();
        DeliveryZone::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Up to 2 km', 'delivery_fee' => 1, 'minimum_order' => 0, 'is_active' => true,
            'area' => ['type' => 'circle', 'center' => [34.1210, 35.6480], 'radius' => 2000]]);
        DeliveryZone::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => '2 to 6 km', 'delivery_fee' => 3, 'minimum_order' => 0, 'is_active' => true,
            'area' => ['type' => 'circle', 'center' => [34.1210, 35.6480], 'radius' => 6000]]);
    }

    public function test_the_same_checkout_question_is_not_sent_twice_in_a_row(): void
    {
        $this->menuOrder();
        $before = $this->questionsSent();

        $this->say('done'); // nothing else: the question is already on the screen

        $this->assertSame($before, $this->questionsSent());
    }

    private function questionsSent(): int
    {
        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && isset($r['interactive']['action']['buttons']))->count();
    }

    public function test_zones_on_the_map_ask_for_the_pin_and_pick_the_zone_from_it(): void
    {
        $this->drawRings();
        $this->menuOrder();
        $this->assertSame('location_request_message', $this->last()['interactive']['type'], 'no area list: the pin decides');

        // About 1 km from the shop: the inner ring, even though the wider ring covers it too.
        $this->inbound(['type' => 'location', 'location' => ['latitude' => 34.1290, 'longitude' => 35.6480]]);
        $this->tap('co:skip:');
        $this->tap('co:cur:USD');
        $this->assertStringContainsString('Up to 2 km', $this->last()['interactive']['body']['text']);
        $this->assertStringContainsString('1.00$', $this->last()['interactive']['body']['text']);
    }

    public function test_a_pin_outside_every_zone_is_told_the_shop_does_not_deliver_there(): void
    {
        $this->drawRings();
        $this->menuOrder();

        $this->inbound(['type' => 'location', 'location' => ['latitude' => 33.8938, 'longitude' => 35.5018]]); // Beirut, far away
        $this->assertSame('location_request_message', $this->last()['interactive']['type'], 'asks for another location');
        $this->assertStringContainsString("doesn't deliver to this location", $this->last()['interactive']['body']['text']);

        $this->inbound(['type' => 'location', 'location' => ['latitude' => 34.1600, 'longitude' => 35.6480]]); // about 4 km
        $this->assertSame(['co:skip:'], $this->buttonIds(), 'inside the wider ring: on to the details');
    }

    public function test_full_delivery_checkout_with_buttons(): void
    {
        $this->menuOrder();
        $this->assertSame('list', $this->last()['interactive']['type'], 'two zones: choose the area');
        $this->assertContains("co:zone:{$this->jbeil->id}", array_column($this->last()['interactive']['action']['sections'][0]['rows'], 'id'));

        $this->pickRow("co:zone:{$this->jbeil->id}");
        $this->assertSame('location_request_message', $this->last()['interactive']['type']);

        $this->inbound(['type' => 'location', 'location' => ['latitude' => 34.12, 'longitude' => 35.65, 'address' => 'Jbeil old souk']]);
        $this->assertSame(['co:skip:'], $this->buttonIds(), 'asks for building / floor');

        $this->say('Building 4, 2nd floor');
        $this->assertSame(['co:cur:USD', 'co:cur:LBP', 'co:cur:MIXED'], $this->buttonIds(), 'cash: dollars, lira or both');
        $this->assertStringStartsWith('Total: 5.50$ = 493,000 LBP', $this->last()['interactive']['body']['text']);

        $this->tap('co:cur:LBP');
        $receipt = $this->last()['interactive']['body']['text'];
        $this->assertStringContainsString('2× Zaatar — 4.00$', $receipt);
        $this->assertStringContainsString('Delivery: 1.50$', $receipt);
        $this->assertStringContainsString('Total: 5.50$*', $receipt);
        $this->assertStringContainsString('Preparation about 25 min. We will message you the arrival time when it is on its way.', $receipt, 'the preparation only: the arrival time comes at dispatch');
        $this->assertStringNotContainsString('At your door', $receipt);
        $this->assertStringContainsString('493,000 LBP', $receipt);
        $this->assertSame(['co:ok:', 'co:time:', 'co:cancel:'], $this->buttonIds(), 'Confirm, Another time, Cancel');
        $this->assertStringContainsString('just write what you want', $receipt);

        $this->tap('co:ok:');
        $order = $this->order();
        $this->assertSame('placed', $order->status, 'the shop answers from its phone');
        $this->assertSame($this->jbeil->id, $order->delivery_zone_id);
        $this->assertSame('LBP', $order->payment_currency);
        $this->assertEquals(34.12, $order->delivery_lat);
        $this->assertStringContainsString('Building 4, 2nd floor', $order->delivery_address);
        $this->assertStringContainsString('Order #1 sent to Foren', $this->last()['text']['body']);
        $this->assertSame([], $this->buttonIds(), 'no status button: the customer asks in words');

        $saved = Customer::query()->withoutGlobalScopes()->where('phone', '+'.self::CUSTOMER)->first()->addresses;
        $this->assertSame('Jbeil', $saved[0]['zone']);
    }

    public function test_a_typed_address_is_not_taken_the_location_pin_is_required(): void
    {
        $this->menuOrder();
        $this->pickRow("co:zone:{$this->jbeil->id}");
        $this->assertSame('location_request_message', $this->last()['interactive']['type']);

        $this->say('Jbeil, Main Street, Building 4, 2nd floor');

        $this->assertSame('location_request_message', $this->last()['interactive']['type'], 'asks for the pin again, with the button');
        $this->assertStringContainsString('location', $this->last()['interactive']['body']['text']);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());

        $this->inbound(['type' => 'location', 'location' => ['latitude' => 34.12, 'longitude' => 35.65]]);
        $this->assertSame(['co:skip:'], $this->buttonIds(), 'the pin is accepted; building and floor are only a note');
    }

    public function test_an_address_the_assistant_heard_becomes_the_note_and_the_pin_is_still_asked(): void
    {
        $this->menuOrder();
        $conversation = Conversation::query()->withoutGlobalScopes()->latest()->firstOrFail();

        app(CheckoutFlow::class)->start($this->merchant, $conversation, 'en', ['fulfillment' => 'delivery', 'delivery_address' => 'Sassine street, bldg Rizk, 3rd floor']);
        $this->pickRow("co:zone:{$this->jbeil->id}");

        $this->assertSame('location_request_message', $this->last()['interactive']['type'], 'a typed address never replaces the pin');

        $this->inbound(['type' => 'location', 'location' => ['latitude' => 34.12, 'longitude' => 35.65]]);
        $this->assertSame(['co:cur:USD', 'co:cur:LBP', 'co:cur:MIXED'], $this->buttonIds(), 'what the customer already typed is kept as the note: no second question');
        $this->tap('co:cur:USD');
        $this->tap('co:ok:');
        $this->assertStringContainsString('Sassine street, bldg Rizk, 3rd floor', $this->order()->delivery_address);
        $this->assertEquals(34.12, $this->order()->delivery_lat);
    }

    public function test_a_delivery_order_with_only_a_typed_address_is_refused_even_when_the_assistant_places_it(): void
    {
        $this->menuOrder();
        $conversation = Conversation::query()->withoutGlobalScopes()->latest()->firstOrFail();

        app(CurrentTenant::class)->set($this->merchant);

        [$reply, $order] = app(ConversationService::class)->placeOrderFromDetails($conversation, [
            'fulfillment' => 'delivery', 'delivery_zone' => 'Jbeil', 'delivery_address' => 'Main Street, Building 4, 2nd floor',
        ]);

        $this->assertNull($order);
        $this->assertStringContainsString('location pin', $reply);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_an_old_saved_address_without_a_pin_is_not_offered(): void
    {
        $this->menuOrder();
        $customer = Customer::query()->withoutGlobalScopes()->where('phone', '+'.self::CUSTOMER)->firstOrFail();
        $customer->forceFill(['addresses' => [['id' => 'old1', 'zone' => 'Jbeil', 'address' => 'Main Street, Building 4', 'location' => null]]])->save();
        $this->tap('co:cancel:');

        $this->menuOrder();

        $rows = array_column($this->last()['interactive']['action']['sections'][0]['rows'] ?? [], 'id');
        $this->assertSame([], array_filter($rows, fn ($id) => str_starts_with($id, 'co:addr:')), 'a typed address from before is not a delivery point');
    }

    public function test_cash_split_between_dollars_and_lira(): void
    {
        $this->menuOrder();
        $this->pickRow("co:zone:{$this->jbeil->id}");
        $this->inbound(['type' => 'location', 'location' => ['latitude' => 34.12, 'longitude' => 35.65]]);
        $this->tap('co:skip:');
        $this->tap('co:cur:MIXED');
        $this->assertStringContainsString('How many dollars will you pay?', $this->last()['text']['body']);

        $this->say('9');
        $this->assertStringContainsString('less than 5.50$', $this->last()['text']['body'], 'more than the total is refused');

        $this->say('5');
        $receipt = $this->last()['interactive']['body']['text'];
        $this->assertStringContainsString('*Total: 5.50$* (493,000 LBP)', $receipt);
        $this->assertStringContainsString('Cash on delivery · 5.00$ + 45,000 LBP', $receipt);

        $this->tap('co:ok:');
        $order = $this->order();
        $this->assertSame('MIXED', $order->payment_currency);
        $this->assertEquals(5.0, (float) $order->cash_main);
        $this->assertEquals(45000, (float) $order->total_lbp);
    }

    public function test_returning_customer_picks_a_saved_address(): void
    {
        $this->test_full_delivery_checkout_with_buttons();
        $customer = Customer::query()->withoutGlobalScopes()->where('phone', '+'.self::CUSTOMER)->first();
        $saved = $customer->addresses[0]['id'];

        $this->menuOrder(1);
        $this->assertSame("co:addr:{$saved}", $this->last()['interactive']['action']['sections'][0]['rows'][0]['id']);
        $this->assertSame('co:addr:new', $this->last()['interactive']['action']['sections'][0]['rows'][1]['id']);

        // 1 × $2 is under Jbeil's $3 minimum: refused as soon as the saved Jbeil address is picked.
        $this->pickRow("co:addr:{$saved}");
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count(), 'below minimum: no second order');
        $this->assertStringContainsString('minimum order for Jbeil', $this->last()['text']['body']);
    }

    public function test_saved_address_goes_straight_to_payment(): void
    {
        $this->test_full_delivery_checkout_with_buttons();
        $saved = Customer::query()->withoutGlobalScopes()->where('phone', '+'.self::CUSTOMER)->first()->addresses[0]['id'];

        $this->menuOrder(3);
        $this->pickRow("co:addr:{$saved}");
        $this->assertSame(['co:cur:USD', 'co:cur:LBP', 'co:cur:MIXED'], $this->buttonIds(), 'no area, location or details questions');
        $this->tap('co:cur:USD');
        $this->tap('co:ok:');

        $this->assertSame(2, $this->order()->number);
        $this->assertStringContainsString('Building 4, 2nd floor', $this->order()->delivery_address);
    }

    public function test_area_under_minimum_is_refused_right_away(): void
    {
        $this->menuOrder(1);
        $this->pickRow("co:zone:{$this->jbeil->id}");

        $this->assertSame('text', $this->last()['type'], 'back to the menu, in words, to add more');
        $this->assertStringContainsString('minimum order for Jbeil is 3.00', $this->last()['text']['body']);
    }

    public function test_whish_offers_dollars_and_lira_but_never_a_split(): void
    {
        $settings = $this->merchant->settings;
        $settings['fulfillment'] = 'both';
        $settings['pickup_address'] = 'Jbeil main road';
        $settings['lbp_rate'] = 89500;
        $settings['whish'] = ['number' => '70123456'];
        $this->merchant->update(['settings' => $settings]);

        $this->menuOrder();
        $this->tap('co:ful:pickup');
        $this->tap('co:pay:whish');

        $this->assertSame(['co:cur:USD', 'co:cur:LBP'], $this->buttonIds());
    }

    public function test_pickup_with_whish_choice(): void
    {
        $settings = $this->merchant->settings;
        $settings['fulfillment'] = 'both';
        $settings['pickup_address'] = 'Jbeil main road';
        $settings['whish'] = ['number' => '70123456'];
        $this->merchant->update(['settings' => $settings]);

        $this->menuOrder();
        $this->assertSame(['co:ful:delivery', 'co:ful:pickup'], $this->buttonIds());

        $this->tap('co:ful:pickup');
        $this->assertSame(['co:pay:cash', 'co:pay:whish'], $this->buttonIds());
        $this->assertSame('💵 Cash at the shop', $this->last()['interactive']['action']['buttons'][0]['reply']['title']);

        $this->tap('co:pay:cash');
        $this->tap('co:cur:USD');
        $this->assertStringContainsString('Pickup at Jbeil main road', $this->last()['interactive']['body']['text']);

        $this->tap('co:ok:');
        $this->assertSame('pickup', $this->order()->fulfillment);
    }

    public function test_typing_something_else_goes_back_to_the_assistant(): void
    {
        $this->menuOrder();
        $this->say('add 1 zaatar');

        $this->assertStringContainsString('Added 1 × Zaatar', $this->last()['text']['body'] ?? $this->last()['interactive']['body']['text']);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_a_change_of_mind_while_asked_for_the_address_is_not_taken_as_the_address(): void
    {
        $this->menuOrder();
        $this->pickRow("co:zone:{$this->jbeil->id}");
        $this->assertSame('location_request_message', $this->last()['interactive']['type']);

        $this->say('actually make it 3');

        $cart = Conversation::query()->withoutGlobalScopes()->firstOrFail()->cart()->withoutGlobalScopes()->first();
        $this->assertSame(3, $cart->items()->withoutGlobalScopes()->first()->quantity, 'the cart is updated');
        $this->assertArrayNotHasKey('checkout', Conversation::query()->withoutGlobalScopes()->firstOrFail()->state ?? [], 'checkout left, nothing saved as the address');
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_a_question_while_asked_for_the_address_is_not_taken_as_the_address(): void
    {
        foreach (['what time do you close today?', 'Vous fermez à quelle heure', 'ايمتى بتسكرو؟', 'addesh el delivery'] as $question) {
            $this->menuOrder();
            $this->pickRow("co:zone:{$this->jbeil->id}");
            $this->assertSame('location_request_message', $this->last()['interactive']['type']);

            $this->say($question);

            $this->assertArrayNotHasKey('checkout', Conversation::query()->withoutGlobalScopes()->firstOrFail()->state ?? [], "\"{$question}\" is not saved as the address");
        }
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_assistant_checkout_starts_the_buttons_and_old_buttons_expire(): void
    {
        $this->say('add 2 zaatar');
        $this->say('done');
        $this->assertSame('list', $this->last()['interactive']['type']);

        $this->tap('co:cancel:');
        $this->assertStringContainsString('cancelled', $this->last()['text']['body']);

        $this->tap('co:ok:');
        $this->assertStringContainsString('already done', $this->last()['text']['body']);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_arabic_continue_and_thumbs_up_open_checkout_when_the_cart_has_items(): void
    {
        $this->assertTrue(CheckoutFlow::looksLikeContinueCheckout('كمل'));
        $this->assertTrue(CheckoutFlow::looksLikeContinueCheckout('👍'));
        $this->assertFalse(CheckoutFlow::looksLikeContinueCheckout('2 manakish'));

        $settings = $this->merchant->settings ?? [];
        $settings['fulfillment'] = 'both';
        $this->merchant->forceFill(['settings' => $settings])->save();

        $this->say('add 2 zaatar');
        $this->say('كمل');
        $ids = $this->buttonIds();
        $this->assertSame(['co:ful:delivery', 'co:ful:pickup'], $ids, 'checkout opens on كمل instead of an AI loop');
        $this->assertArrayHasKey('checkout', Conversation::query()->withoutGlobalScopes()->firstOrFail()->state ?? []);
    }
}
