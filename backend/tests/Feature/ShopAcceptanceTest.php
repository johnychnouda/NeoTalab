<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\NotifyCustomerOfOrderStatus;
use App\Jobs\StartScheduledOrder;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\SystemAlert;
use App\Models\WhatsAppMessage;
use App\Services\OrderTiming;
use App\Services\WhatsApp\CustomerTemplates;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\OrderMessage;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ShopAcceptanceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, \Tests\Concerns\ShopPhone;

    private const CUSTOMER = '+96170123456';

    private const STAFF = '+96171555000';

    private Merchant $merchant;

    private Product $shawarma;

    private int $seq = 0;

    private int|string $google = 1200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00', 'Asia/Beirut')); // a Wednesday
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake([
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200),
            // Google's answer is whatever the test sets in $this->google: seconds, or 'down'.
            'routes.googleapis.com/*' => fn () => $this->google === 'down'
                ? Http::response('down', 503)
                : Http::response(['routes' => [['duration' => $this->google.'s']]], 200),
        ]);
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class, \App\Jobs\NotifyShopOfOrder::class]);

        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Beirut Bites', 'currency' => 'USD', 'timezone' => 'Asia/Beirut',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'prep_time_mins' => 25],
        ]);
        $this->shawarma = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shawarma', 'price' => 6]);
        Driver::query()->forceCreate([
            'merchant_id' => $this->merchant->id, 'name' => 'Owner', 'role' => 'staff', 'phone' => self::STAFF, 'whatsapp_number' => self::STAFF,
            'password' => 'x', 'status' => 'on_duty', 'availability' => 'available', 'is_active' => true,
        ]);
        $this->staffWroteAt(now()->subHour()); // inside WhatsApp's 24-hour window
    }

    private function staffWroteAt(Carbon $at): void
    {
        WhatsAppMessage::query()->withoutGlobalScopes()->where('wa_message_id', 'like', 'wamid.HELLO%')->delete();
        $this->staffWroteToShop($this->merchant, self::STAFF, $at);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receive(array $msg, string $from): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [$msg + ['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+')]],
        ]]]]]]);
    }

    private function tap(string $id): void
    {
        if (str_starts_with($id, 'stf:')) {
            $this->shopTap($id);

            return;
        }

        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']]], self::STAFF);
    }

    /** The customer asks in words; the assistant recognises it and the server answers with the real status. */
    private function askWhereIsMyOrder(): void
    {
        $this->receive(['type' => 'text', 'text' => ['body' => 'where is my order']], self::CUSTOMER);
    }

    private function customerTaps(string $id): void
    {
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']]], self::CUSTOMER);
    }

    private function order(string $details = '| Bliss St, Building 4 | cash'): Order
    {
        $this->receive(['type' => 'text', 'text' => ['body' => 'add 2 shawarma']], self::CUSTOMER);
        $this->receive(['type' => 'text', 'text' => ['body' => "confirm | {$details}"]], self::CUSTOMER);

        return Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->latest('number')->firstOrFail();
    }

    /** @return array{text: string, buttons: list<string>, titles: list<string>} */
    private function lastTo(string $phone): array
    {
        $r = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === ltrim($phone, '+'))->last();

        return [
            'text' => $r['text']['body'] ?? ($r['interactive']['body']['text'] ?? ''),
            'buttons' => collect($r['interactive']['action']['buttons'] ?? [])->pluck('reply.id')->all(),
            'titles' => collect($r['interactive']['action']['buttons'] ?? [])->pluck('reply.title')->all(),
        ];
    }

    private function settings(array $values): void
    {
        $this->merchant->update(['settings' => array_merge($this->merchant->fresh()->settings, $values)]);
    }

    /** Marks the shop's customer templates (English) as approved by Meta. */
    private function approveCustomerTemplates(string ...$events): void
    {
        $bot = $this->merchant->fresh()->settings['bot'];
        foreach ($events as $event) {
            $bot['templates'][CustomerTemplates::name($event, 'en')] = ['status' => 'APPROVED'];
        }
        $this->settings(['bot' => $bot]);
    }

    public function test_order_waits_for_the_shop_and_accept_gives_an_arrival_time(): void
    {
        DeliveryZone::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Hamra', 'delivery_fee' => 1, 'minimum_order' => 0, 'delivery_mins' => 20, 'is_active' => true]);
        $order = $this->order('Hamra | Bliss St, Building 4 | cash');

        $this->assertSame('placed', $order->status);
        $this->assertStringContainsString('sent to Beirut Bites', $this->lastTo(self::CUSTOMER)['text']);
        Bus::assertDispatched(CheckUnacceptedOrder::class, 2);

        $this->tap("stf:accept:{$order->id}");

        // 25 min preparation + 20 min drive to Hamra = 10:45 is the shop's promise; the customer is first told when it leaves (10:25).
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('2026-10-14 10:45', $order->fresh()->promised_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
        $this->assertSame('✅ Beirut Bites accepted your order #1 — preparing now, ready around 10:25 AM.', $this->lastTo(self::CUSTOMER)['text']);
    }

    public function test_google_maps_gives_the_driving_time_from_the_customers_pin(): void
    {
        config(['services.google_maps.key' => 'test-key']);
        $this->settings(['shop_location' => 'https://maps.google.com/?q=33.8959,35.4784']);
        $this->receive(['type' => 'location', 'location' => ['latitude' => 33.88, 'longitude' => 35.51]], self::CUSTOMER);
        $order = $this->order('| | cash');

        $this->tap("stf:accept:{$order->id}");

        // 25 min preparation + 20 min from Google (1200 s).
        $this->assertSame('10:45', $order->fresh()->promised_at->setTimezone('Asia/Beirut')->format('H:i'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'routes.googleapis.com') && $r['destination']['location']['latLng']['latitude'] == 33.88
            && $r['routingPreference'] === 'TRAFFIC_UNAWARE');

        // Leaving the shop asks Google again, now with live traffic, for the arrival time the customer is told.
        $this->google = 1800;
        $this->tap("stf:picked_up:{$order->id}");
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_contains($r->url(), 'routes.googleapis.com')));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'routes.googleapis.com') && $r['routingPreference'] === 'TRAFFIC_AWARE');
        $this->assertSame('10:30', $order->fresh()->promised_at->setTimezone('Asia/Beirut')->format('H:i'), '30 min with traffic');
        $this->assertSame('🛵 Your order #1 is on its way — arriving in about 30 min (around 10:30 AM).', $this->lastTo(self::CUSTOMER)['text']);
    }

    public function test_when_google_cannot_answer_at_dispatch_the_saved_driving_time_is_used(): void
    {
        config(['services.google_maps.key' => 'test-key']);
        $this->settings(['shop_location' => 'https://maps.google.com/?q=33.8959,35.4784']);
        $this->receive(['type' => 'location', 'location' => ['latitude' => 33.88, 'longitude' => 35.51]], self::CUSTOMER);
        $order = $this->order('| | cash');
        $this->tap("stf:accept:{$order->id}");

        $this->google = 'down';
        $this->tap("stf:picked_up:{$order->id}");

        $this->assertSame('10:20', $order->fresh()->promised_at->setTimezone('Asia/Beirut')->format('H:i'), 'the 20 min saved when it was accepted');
        $this->assertStringContainsString('arriving in about 20 min', $this->lastTo(self::CUSTOMER)['text']);
    }

    public function test_shop_can_refuse(): void
    {
        $order = $this->order();
        $this->tap("stf:reject:{$order->id}");

        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertStringContainsString('could not accept your order #1', $this->lastTo(self::CUSTOMER)['text']);
    }

    public function test_unanswered_order_is_reminded_then_politely_declined(): void
    {
        $order = $this->order();

        app()->call([new CheckUnacceptedOrder($order->id), 'handle']);
        $this->assertStringContainsString('Order #1 is still waiting for Beirut Bites', $this->lastTo(self::CUSTOMER)['text'], 'the reminder shows in the chat on the shop phone');

        app()->call([new CheckUnacceptedOrder($order->id, true), 'handle']);
        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertStringContainsString('could not confirm your order #1 right now', $this->lastTo(self::CUSTOMER)['text']);
    }

    public function test_food_order_gets_a_new_time_when_running_late_and_on_pickup(): void
    {
        $order = $this->order();
        $this->tap("stf:accept:{$order->id}");

        Carbon::setTestNow(now()->addMinutes(60));
        app()->call([new CheckLateOrder($order->id), 'handle']);
        $this->assertStringContainsString('taking a little longer — new time: preparing now, ready around 11:10 AM', $this->lastTo(self::CUSTOMER)['text']);

        $this->tap("stf:picked_up:{$order->id}");
        $this->assertSame('🛵 Your order #1 is on its way — arriving in about 15 min (around 11:15 AM).', $this->lastTo(self::CUSTOMER)['text'], 'no driver: shop delivers itself, the arrival time comes now');
        $this->assertSame('11:15', $order->fresh()->promised_at->setTimezone('Asia/Beirut')->format('H:i'), '15 min drive from now');
    }

    public function test_days_shop_promises_a_date_and_handles_delays(): void
    {
        $this->settings(['timing_unit' => 'days', 'delivery_days_min' => 2, 'delivery_days_max' => 3]);
        $order = $this->order();

        $this->tap("stf:accept:{$order->id}");
        // Wednesday + 3 working days (no Sundays set as closed → Sunday skipped) = Saturday 17 October.
        $this->assertSame('✅ Beirut Bites accepted your order #1 — delivered by Saturday 17 October.', $this->lastTo(self::CUSTOMER)['text']);

        Carbon::setTestNow(Carbon::parse('2026-10-17 09:30', 'Asia/Beirut'));

        // Three days after the customer last wrote, WhatsApp only accepts the approved template.
        $this->approveCustomerTemplates(CustomerTemplates::DELAYED);
        $this->tap("stf:delay:{$order->id}");
        $template = $this->lastTemplateTo(self::CUSTOMER);
        $this->assertSame('neotalab_c_order_delayed_en', $template['template']['name']);
        $this->assertSame(['1', 'Beirut Bites', 'delivered by Monday 19 October'], array_column($template['template']['components'][0]['parameters'], 'text'));

        // Nobody touched it on Monday: on Tuesday it moves on by itself.
        Carbon::setTestNow(Carbon::parse('2026-10-20 08:00', 'Asia/Beirut'));
        Artisan::call('orders:due');
        $this->assertStringContainsString('delivered by Wednesday 21 October', json_encode($this->lastTemplateTo(self::CUSTOMER)['template']['components'][0]['parameters']));
    }

    public function test_days_shop_pickup_estimate_says_ready_for_pickup(): void
    {
        $this->settings(['timing_unit' => 'days', 'delivery_days_min' => 1, 'delivery_days_max' => 2]);

        $this->assertSame('Ready for pickup in 1–2 working days', app(OrderTiming::class)->estimate($this->merchant, 'pickup', 'en'));
        $this->assertSame('Delivered in 1–2 working days', app(OrderTiming::class)->estimate($this->merchant, 'delivery', 'en'));
    }

    public function test_a_late_kitchen_is_reported_when_the_customer_was_told_it_would_leave_not_at_the_arrival_time(): void
    {
        $order = $this->order();
        $this->tap("stf:accept:{$order->id}");

        // 25 min preparation + 15 min drive: leaves 10:25, arrives 10:40. The first check is 5 min after it should leave.
        Bus::assertDispatched(CheckLateOrder::class, fn ($job) => $job->orderId === $order->id
            && $job->delay->setTimezone('Asia/Beirut')->format('H:i') === '10:30');

        Carbon::setTestNow(Carbon::parse('2026-10-14 10:20', 'Asia/Beirut'));
        app()->call([new CheckLateOrder($order->id), 'handle']);
        $this->assertStringContainsString('accepted your order', $this->lastTo(self::CUSTOMER)['text'], 'still on time: nothing is said');

        Carbon::setTestNow(Carbon::parse('2026-10-14 10:31', 'Asia/Beirut'));
        app()->call([new CheckLateOrder($order->id), 'handle']);
        $this->assertStringContainsString('taking a little longer — new time: preparing now, ready around 10:41 AM', $this->lastTo(self::CUSTOMER)['text']);
    }

    public function test_nothing_more_is_sent_automatically_once_the_order_is_on_its_way(): void
    {
        $order = $this->order();
        $this->tap("stf:accept:{$order->id}");

        Carbon::setTestNow(Carbon::parse('2026-10-14 10:30', 'Asia/Beirut'));
        $this->tap("stf:picked_up:{$order->id}");
        $arrival = $this->lastTo(self::CUSTOMER)['text'];
        $this->assertSame('🛵 Your order #1 is on its way — arriving in about 15 min (around 10:45 AM).', $arrival);

        // No late check is scheduled for the road: only the one from the kitchen stage, which is over.
        Bus::assertDispatchedTimes(CheckLateOrder::class, 1);

        // Even if that old check runs later, the customer is not told anything new.
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:55', 'Asia/Beirut'));
        app()->call([new CheckLateOrder($order->id), 'handle']);
        $this->assertSame($arrival, $this->lastTo(self::CUSTOMER)['text']);
        $this->assertSame('10:45', $order->fresh()->promised_at->setTimezone('Asia/Beirut')->format('H:i'));
    }

    public function test_driver_gets_im_outside_and_customer_can_track_the_order(): void
    {
        $rami = Driver::query()->forceCreate([
            'merchant_id' => $this->merchant->id, 'name' => 'Rami', 'role' => 'driver', 'phone' => '+96176000001', 'whatsapp_number' => '+96176000001',
            'password' => 'x', 'status' => 'on_duty', 'availability' => 'available', 'is_active' => true, 'shift_started_at' => now(),
        ]);
        $this->receive(['type' => 'location', 'location' => ['latitude' => 33.88, 'longitude' => 35.51]], self::CUSTOMER);
        $order = $this->order('| | cash');
        $this->askWhereIsMyOrder();
        $this->assertStringContainsString('waiting for Beirut Bites to accept', $this->lastTo(self::CUSTOMER)['text']);

        $this->tap("stf:accept:{$order->id}");
        $this->assertSame($rami->id, $order->fresh()->driver_id);
        $this->assertSame([], $this->lastTo(self::CUSTOMER)['buttons'], 'no status button: the customer asks in words');
        $pin = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => ($r['to'] ?? '') === '96176000001' && $r['type'] === 'location');
        $this->assertEquals(33.88, $pin['location']['latitude'], 'driver gets the customer location inside WhatsApp');

        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "drv:picked_up:{$order->id}", 'title' => 'x']]], '+96176000001');
        $card = $this->lastTo('+96176000001');
        $this->assertStringContainsString('on the way', $card['text']);
        $this->assertSame(["drv:outside:{$order->id}", "drv:delivered:{$order->id}", "drv:failed:{$order->id}"], $card['buttons']);
        $this->askWhereIsMyOrder();
        $this->assertStringContainsString('is on its way', $this->lastTo(self::CUSTOMER)['text']);
        $this->assertStringContainsString('Rami · 📞 +96176000001', $this->lastTo(self::CUSTOMER)['text']);

        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "drv:outside:{$order->id}", 'title' => 'x']]], '+96176000001');
        $this->assertSame('📍 Rami is outside with your order #1! 📞 '.OrderMessage::phone('+96176000001'), $this->lastTo(self::CUSTOMER)['text']);
    }

    public function test_status_button_only_answers_for_the_customers_own_order(): void
    {
        $order = $this->order();
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "trk:{$order->id}", 'title' => 'x']]], '+96170999888');

        $this->assertStringNotContainsString('Order #1', $this->lastTo('+96170999888')['text']);
    }

    public function test_customer_can_cancel_while_the_shop_has_not_accepted_yet(): void
    {
        $order = $this->order();
        $this->assertSame('placed', $order->status);

        $this->receive(['type' => 'text', 'text' => ['body' => 'cancel order']], self::CUSTOMER);
        $this->assertSame('placed', $order->fresh()->status, 'asks first');
        $this->customerTaps("cx:yes:{$order->id}");

        $this->assertSame('cancelled', $order->fresh()->status);

        // A ✅ typed afterwards must not accept it now.
        $this->tap("stf:accept:{$order->id}");
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    // ── staff outside WhatsApp's 24-hour window ─────────────────────────────

    private function approveTemplates(): void
    {
        $this->settings(['bot' => ['phone_id' => '555', 'templates' => [
            ShopTemplateService::NEW_ORDER => ['status' => 'APPROVED'],
            ShopTemplateService::STAFF_ALERT => ['status' => 'APPROVED'],
        ]]]);
    }

    /** @return Request|null the last template message sent to the number */
    private function lastTemplateTo(string $phone): ?Request
    {
        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === ltrim($phone, '+') && $r['type'] === 'template')->last();
    }

    public function test_a_customer_inside_the_window_still_gets_plain_text(): void
    {
        $order = $this->order();
        $this->tap("stf:accept:{$order->id}");

        $this->assertStringContainsString('accepted your order #1', $this->lastTo(self::CUSTOMER)['text']);
        $this->assertNull($this->lastTemplateTo(self::CUSTOMER));
    }

    /** @return array{0: string, 1: list<string>} the template's name and variables sent to the customer */
    private function customerTemplate(): array
    {
        $sent = $this->lastTemplateTo(self::CUSTOMER);
        $this->assertNotNull($sent, 'a template went to the customer');

        return [$sent['template']['name'], array_column($sent['template']['components'][0]['parameters'], 'text')];
    }

    public function test_status_messages_days_later_use_the_matching_template(): void
    {
        $this->approveCustomerTemplates(CustomerTemplates::ON_THE_WAY, CustomerTemplates::READY, CustomerTemplates::CANCELLED);
        $this->settings(['pickup_address' => 'Hamra, Bliss St']);
        $order = $this->order();
        $this->travel(3)->days();

        $order->forceFill(['status' => 'picked_up'])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);
        $this->assertSame(['neotalab_c_order_on_the_way_en', ['1', 'Beirut Bites']], $this->customerTemplate());

        $order->forceFill(['status' => 'ready', 'fulfillment' => 'pickup'])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);
        $this->assertSame('neotalab_c_order_ready_en', $this->customerTemplate()[0]);
        $this->assertSame('Hamra, Bliss St', $this->customerTemplate()[1][2]);

        $order->forceFill(['status' => 'cancelled'])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);
        $this->assertSame(['neotalab_c_order_cancelled_en', ['1', 'Beirut Bites']], $this->customerTemplate());
    }

    public function test_a_paid_order_cancelled_days_later_tells_the_customer_the_money_comes_back(): void
    {
        $this->approveCustomerTemplates(CustomerTemplates::REFUND, CustomerTemplates::CANCELLED);
        $order = $this->order();
        $this->travel(3)->days();

        $order->forceFill(['status' => 'cancelled', 'payment_status' => 'refund_due'])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);
        $this->assertSame(['neotalab_c_order_refund_en', ['1', 'Beirut Bites']], $this->customerTemplate());

        $order->forceFill(['payment_status' => 'unpaid'])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);
        $this->assertSame('neotalab_c_order_cancelled_en', $this->customerTemplate()[0]);
    }

    /** Staff who last wrote to the shop two days ago: only approved templates reach them. */
    private function staffOutsideTheWindow(string ...$templates): void
    {
        $this->staffWroteAt(now()->subDays(2));
        $bot = $this->merchant->fresh()->settings['bot'];
        foreach ($templates as $name) {
            $bot['templates'][$name] = ['status' => 'APPROVED'];
        }
        $this->settings(['bot' => $bot]);
    }

    public function test_a_scheduled_order_reminder_days_later_reaches_the_customer_by_template(): void
    {
        $this->approveCustomerTemplates(CustomerTemplates::SCHEDULED);
        $order = $this->order();
        $order->forceFill(['status' => 'confirmed', 'deliver_at' => now()->addDays(3), 'promised_at' => now()->addDays(3), 'fulfillment' => 'pickup'])->save();
        $this->travel(3)->days();
        $this->staffOutsideTheWindow();

        app()->call([new StartScheduledOrder($order->id), 'handle']);

        $this->assertSame('neotalab_c_order_scheduled_en', $this->customerTemplate()[0]);
    }

    public function test_a_delivery_days_later_thanks_the_customer_and_asks_for_the_rating_by_template(): void
    {
        $this->approveCustomerTemplates(CustomerTemplates::DELIVERED_RATE, CustomerTemplates::DELIVERED);
        $order = $this->order();
        $this->travel(3)->days();

        $order->forceFill(['status' => 'delivered'])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);

        $this->assertSame(['neotalab_c_order_delivered_rate_en', ['1', 'Beirut Bites']], $this->customerTemplate());
        $this->assertNotNull($order->fresh()->rating_requested_at, 'the customer can answer with a number');
    }

    public function test_a_rating_question_that_could_not_be_sent_is_asked_again_next_time(): void
    {
        $order = $this->order();
        $this->travel(3)->days();

        $order->forceFill(['status' => 'delivered'])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id); // no approved template: nothing could be sent

        $this->assertNull($order->fresh()->rating_requested_at);
    }

    public function test_the_driver_at_the_door_days_later_reaches_the_customer_by_template(): void
    {
        $this->approveCustomerTemplates(CustomerTemplates::OUTSIDE);
        Driver::query()->forceCreate([
            'merchant_id' => $this->merchant->id, 'name' => 'Rami', 'role' => 'driver', 'phone' => '+96176000001', 'whatsapp_number' => '+96176000001',
            'password' => 'x', 'status' => 'on_duty', 'availability' => 'available', 'is_active' => true, 'shift_started_at' => now(),
        ]);
        $this->receive(['type' => 'location', 'location' => ['latitude' => 33.88, 'longitude' => 35.51]], self::CUSTOMER);
        $order = $this->order('| | cash');
        $this->tap("stf:accept:{$order->id}");
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "drv:picked_up:{$order->id}", 'title' => 'x']]], '+96176000001');

        $this->travel(3)->days();
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "drv:outside:{$order->id}", 'title' => 'x']]], '+96176000001');

        $this->assertSame(['neotalab_c_order_outside_en', ['Rami', '1', '+96176000001']], $this->customerTemplate());
    }

    public function test_a_thank_you_days_later_is_skipped_without_bothering_the_owner(): void
    {
        $order = $this->order();
        $this->travel(3)->days();

        $order->forceFill(['status' => 'delivered'])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);

        $this->assertStringStartsWith('🧾 Order #1 sent', $this->lastTo(self::CUSTOMER)['text']);
        $this->assertNull($this->lastTemplateTo(self::CUSTOMER));
        $this->assertSame(0, SystemAlert::query()->count(), 'a missed thank-you is not a problem to act on');
    }

    public function test_the_customers_language_picks_the_template_and_english_is_the_fallback(): void
    {
        $this->approveCustomerTemplates(CustomerTemplates::ON_THE_WAY);
        $order = $this->order();
        $this->travel(3)->days();
        $order->forceFill(['status' => 'picked_up'])->save();

        // Arabic customer, only English approved: English goes out rather than nothing.
        $order->customer->forceFill(['locale' => 'ar'])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);
        $this->assertSame('neotalab_c_order_on_the_way_en', $this->customerTemplate()[0]);

        // Arabic approved too: the customer gets Arabic, marked with the Arabic language code.
        $bot = $this->merchant->fresh()->settings['bot'];
        $bot['templates']['neotalab_c_order_on_the_way_ar'] = ['status' => 'APPROVED'];
        $this->settings(['bot' => $bot]);
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);
        $sent = $this->lastTemplateTo(self::CUSTOMER);
        $this->assertSame('neotalab_c_order_on_the_way_ar', $sent['template']['name']);
        $this->assertSame('ar', $sent['template']['language']['code']);

        // A language we have no template in falls back to English.
        $order->customer->forceFill(['locale' => 'de'])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);
        $this->assertSame('neotalab_c_order_on_the_way_en', $this->customerTemplate()[0]);
    }

    public function test_a_late_order_notice_after_the_window_uses_the_delayed_template(): void
    {
        $this->approveCustomerTemplates(CustomerTemplates::DELAYED);
        $order = $this->order();
        $this->tap("stf:accept:{$order->id}");
        $this->travel(2)->days();
        $order->forceFill(['promised_at' => now()->subMinutes(5)])->save();

        app()->call([new CheckLateOrder($order->id), 'handle']);

        $this->assertSame('neotalab_c_order_delayed_en', $this->customerTemplate()[0]);
        $this->assertSame(['1', 'Beirut Bites'], array_slice($this->customerTemplate()[1], 0, 2));
    }

    // ── photos ──────────────────────────────────────────────────────────────

    public function test_a_photo_pauses_the_bot(): void
    {
        $this->receive(['type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg']], self::CUSTOMER);

        $this->assertStringContainsString('we got your photo', $this->lastTo(self::CUSTOMER)['text']);
        $customer = Customer::query()->withoutGlobalScopes()->where('phone', self::CUSTOMER)->sole();
        $this->assertTrue($customer->bot_paused_until->isFuture());

        // The bot does not answer the chat itself until the staff hand it back: the customer gets
        // one short note from the team, then nothing more for ten minutes (no flooding).
        $before = Http::recorded()->count();
        $this->receive(['type' => 'text', 'text' => ['body' => 'hello?']], self::CUSTOMER);
        $this->assertSame($before + 1, Http::recorded()->count());
        $this->assertStringContainsString('team member', $this->lastTo(self::CUSTOMER)['text']);

        $this->receive(['type' => 'sticker', 'sticker' => ['id' => 'st-1']], self::CUSTOMER);
        $this->assertSame($before + 1, Http::recorded()->count());
    }

    /** @return array<string, mixed> the last raw message sent to the phone */
    private function lastRaw(string $phone): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === ltrim($phone, '+'))->last()?->data() ?? [];
    }
}
