<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Services\WhishPayService;
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
    use InteractsWithTenants, RefreshDatabase;

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

    /** Orders through the menu inside the chat: item → quantity → Checkout. */
    private function menuOrder(int $qty = 2): void
    {
        $this->pickRow("mn:item:{$this->zaatar->id}");
        $this->tap("mn:qty:{$qty}");
        $this->tap('co:start:');
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
        $this->assertStringContainsString('Delivery (Jbeil): 1.50$', $receipt);
        $this->assertStringContainsString('Total: 5.50$*', $receipt);
        $this->assertStringContainsString('493,000 LBP', $receipt);
        $this->assertSame(['co:ok:', 'co:edit:', 'co:cancel:'], $this->buttonIds());

        $this->tap('co:ok:');
        $order = $this->order();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame($this->jbeil->id, $order->delivery_zone_id);
        $this->assertSame('LBP', $order->payment_currency);
        $this->assertEquals(34.12, $order->delivery_lat);
        $this->assertStringContainsString('Building 4, 2nd floor', $order->delivery_address);
        $this->assertStringContainsString('Order #1 confirmed', $this->last()['interactive']['body']['text']);
        $this->assertSame(["trk:{$order->id}"], $this->buttonIds(), "with a Where's my order? button");

        $saved = Customer::query()->withoutGlobalScopes()->where('phone', '+'.self::CUSTOMER)->first()->addresses;
        $this->assertSame('Jbeil', $saved[0]['zone']);
    }

    public function test_cash_split_between_dollars_and_lira(): void
    {
        $this->menuOrder();
        $this->pickRow("co:zone:{$this->jbeil->id}");
        $this->say('Bliss St, Building 4');
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
        $this->assertStringContainsString('minimum order for Jbeil', $this->last()['interactive']['body']['text']);
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

        $this->assertSame('list', $this->last()['interactive']['type'], 'back to the menu to add more');
        $this->assertStringContainsString('minimum order for Jbeil is 3.00', $this->last()['interactive']['body']['text']);
    }

    public function test_pickup_with_whish_choice(): void
    {
        $settings = $this->merchant->settings;
        $settings['fulfillment'] = 'both';
        $settings['pickup_address'] = 'Jbeil main road';
        WhishPayService::applySettings($settings, '10199', 'secret', true);
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

    public function test_assistant_checkout_starts_the_buttons_and_old_buttons_expire(): void
    {
        $this->say('add 2 zaatar');
        $this->say('done');
        $this->assertSame('list', $this->last()['interactive']['type']);

        $this->tap('co:cancel:');
        $this->assertStringContainsString('cancelled', $this->last()['text']['body']);

        $this->tap('co:ok:');
        $this->assertStringContainsString('already done', $this->last()['interactive']['body']['text']);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }
}
