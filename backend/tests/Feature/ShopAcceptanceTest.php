<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\NotifyCustomerOfOrderStatus;
use App\Jobs\NotifyStaffOfCancellation;
use App\Jobs\NotifyStaffOfOrder;
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
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '+96170123456';

    private const STAFF = '+96171555000';

    private Merchant $merchant;

    private Product $shawarma;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00', 'Asia/Beirut')); // a Wednesday
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake([
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200),
            'routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '1200s']]], 200),
        ]);
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class]);

        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Beirut Bites', 'currency' => 'USD', 'timezone' => 'Asia/Beirut',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'prep_time_mins' => 25],
        ]);
        $this->shawarma = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shawarma', 'price' => 6, 'track_inventory' => true, 'stock_quantity' => 10]);
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
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']]], self::STAFF);
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
        $ticket = $this->lastTo(self::STAFF);
        $this->assertSame(["stf:accept:{$order->id}", "stf:reject:{$order->id}"], $ticket['buttons']);
        $this->assertSame(['✅ Accept · 25 min', "❌ Can't take it"], $ticket['titles']);
        Bus::assertDispatched(CheckUnacceptedOrder::class, 2);

        $this->tap("stf:accept:{$order->id}");

        // 25 min preparation + 20 min drive to Hamra = 10:45.
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('2026-10-14 10:45', $order->fresh()->promised_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
        $this->assertSame('✅ Beirut Bites accepted your order #1 — arriving around 10:45 AM.', $this->lastTo(self::CUSTOMER)['text']);
        $this->assertSame(["stf:picked_up:{$order->id}", "stf:delivered:{$order->id}"], $this->lastTo(self::STAFF)['buttons']);
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

        // Leaving the shop reuses the same driving time: Google is asked once per order.
        $this->tap("stf:picked_up:{$order->id}");
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'routes.googleapis.com')));
    }

    public function test_shop_can_refuse_and_stock_comes_back(): void
    {
        $order = $this->order();
        $this->assertSame(8, $this->shawarma->fresh()->stock_quantity);

        $this->tap("stf:reject:{$order->id}");

        $this->assertSame('rejected', $order->fresh()->status);
        $this->assertStringContainsString('could not accept your order #1', $this->lastTo(self::CUSTOMER)['text']);
        $this->assertStringContainsString('refused', $this->lastTo(self::STAFF)['text']);
        $this->assertSame(10, $this->shawarma->fresh()->stock_quantity);
    }

    public function test_unanswered_order_is_reminded_then_politely_declined(): void
    {
        $order = $this->order();

        app()->call([new CheckUnacceptedOrder($order->id), 'handle']);
        $this->assertStringStartsWith('⏰ Still waiting — order #1', $this->lastTo(self::STAFF)['text']);

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
        $this->assertStringContainsString('taking a little longer — new time: arriving around 11:25 AM', $this->lastTo(self::CUSTOMER)['text']);

        $this->tap("stf:picked_up:{$order->id}");
        $this->assertSame('🛵 Your order #1 is on its way!', $this->lastTo(self::CUSTOMER)['text'], 'no driver: shop delivers itself');
        $this->assertSame('11:15', $order->fresh()->promised_at->setTimezone('Asia/Beirut')->format('H:i'), '15 min drive from now');
    }

    public function test_days_shop_promises_a_date_reminds_on_the_day_and_handles_delays(): void
    {
        $this->settings(['timing_unit' => 'days', 'delivery_days_min' => 2, 'delivery_days_max' => 3]);
        $order = $this->order();
        $this->assertSame(['✅ Accept · 2–3 days', "❌ Can't take it"], $this->lastTo(self::STAFF)['titles']);

        $this->tap("stf:accept:{$order->id}");
        // Wednesday + 3 working days (no Sundays set as closed → Sunday skipped) = Saturday 17 October.
        $this->assertSame('✅ Beirut Bites accepted your order #1 — delivered by Saturday 17 October.', $this->lastTo(self::CUSTOMER)['text']);

        Carbon::setTestNow(Carbon::parse('2026-10-17 09:30', 'Asia/Beirut'));
        $this->staffWroteAt(now()->subHour());
        Artisan::call('orders:due');
        $due = $this->lastTo(self::STAFF);
        $this->assertStringStartsWith('📦 Order #1 is due today', $due['text']);
        $this->assertStringContainsString('(💵 cash)', $due['text'], 'the shop delivering itself knows what to collect');
        $this->assertSame(["stf:picked_up:{$order->id}", "stf:delay:{$order->id}"], $due['buttons']);

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

        $this->assertSame('Ready for pickup in 1–2 working days', app(OrderTiming::class)->estimate($this->merchant, 'pickup', null, 'en'));
        $this->assertSame('Delivered in 1–2 working days', app(OrderTiming::class)->estimate($this->merchant, 'delivery', null, 'en'));
    }

    public function test_shop_without_staff_keeps_the_bot_accepting(): void
    {
        Driver::query()->withoutGlobalScopes()->where('role', 'staff')->delete();

        $order = $this->order();

        $this->assertSame('confirmed', $order->status);
        $this->assertStringContainsString('confirmed — arriving around', $this->lastTo(self::CUSTOMER)['text']);
    }

    public function test_driver_gets_im_outside_and_customer_can_track_the_order(): void
    {
        $rami = Driver::query()->forceCreate([
            'merchant_id' => $this->merchant->id, 'name' => 'Rami', 'role' => 'driver', 'phone' => '+96176000001', 'whatsapp_number' => '+96176000001',
            'password' => 'x', 'status' => 'on_duty', 'availability' => 'available', 'is_active' => true, 'shift_started_at' => now(),
        ]);
        $this->receive(['type' => 'location', 'location' => ['latitude' => 33.88, 'longitude' => 35.51]], self::CUSTOMER);
        $order = $this->order('| | cash');
        $this->customerTaps("trk:{$order->id}");
        $this->assertStringContainsString('waiting for Beirut Bites to accept', $this->lastTo(self::CUSTOMER)['text']);

        $this->tap("stf:accept:{$order->id}");
        $this->assertSame($rami->id, $order->fresh()->driver_id);
        $this->assertSame(["trk:{$order->id}"], $this->lastTo(self::CUSTOMER)['buttons'], "accepted message has Where's my order?");
        $pin = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => ($r['to'] ?? '') === '96176000001' && $r['type'] === 'location');
        $this->assertEquals(33.88, $pin['location']['latitude'], 'driver gets the customer location inside WhatsApp');

        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "drv:picked_up:{$order->id}", 'title' => 'x']]], '+96176000001');
        $card = $this->lastTo('+96176000001');
        $this->assertStringContainsString('on the way', $card['text']);
        $this->assertSame(["drv:outside:{$order->id}", "drv:delivered:{$order->id}", "drv:failed:{$order->id}"], $card['buttons']);
        $this->customerTaps("trk:{$order->id}");
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
        $this->assertSame(10, $this->shawarma->fresh()->stock_quantity, 'stock is returned');
        $this->assertStringContainsString("#{$order->number} was cancelled by the customer", $this->lastTo(self::STAFF)['text']);

        // The ticket they still have must not be accepted now.
        $this->tap("stf:accept:{$order->id}");
        $this->assertStringContainsString("#{$order->number} was cancelled by the customer", $this->lastTo(self::STAFF)['text']);
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

    public function test_silent_staff_without_an_approved_template_means_the_bot_accepts(): void
    {
        $this->staffWroteAt(now()->subHours(30));

        $order = $this->order();

        $this->assertSame('confirmed', $order->status);
        $this->assertSame([], Http::recorded()->filter(fn ($p) => str_ends_with($p[0]->url(), '/messages') && $p[0]['to'] === ltrim(self::STAFF, '+'))->all());
        Bus::assertNotDispatched(CheckUnacceptedOrder::class);
    }

    public function test_silent_staff_get_the_ticket_as_a_template_and_can_accept_from_it(): void
    {
        $this->staffWroteAt(now()->subHours(30));
        $this->approveTemplates();

        $order = $this->order();

        $this->assertSame('placed', $order->status);
        $ticket = $this->lastTemplateTo(self::STAFF);
        $this->assertSame(ShopTemplateService::NEW_ORDER, $ticket['template']['name']);
        $this->assertSame(['1', 'Beirut Bites'], array_slice(array_column($ticket['template']['components'][0]['parameters'], 'text'), 0, 2));
        $this->assertStringNotContainsString("\n", $ticket['template']['components'][0]['parameters'][2]['text']);
        $this->assertSame(["stf:accept:{$order->id}", "stf:reject:{$order->id}"], [
            $ticket['template']['components'][1]['parameters'][0]['payload'],
            $ticket['template']['components'][2]['parameters'][0]['payload'],
        ]);

        // A template's quick reply comes back as a "button" message with the payload.
        $this->receive(['type' => 'button', 'button' => ['payload' => "stf:accept:{$order->id}", 'text' => 'Accept']], self::STAFF);

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertStringContainsString('accepted your order #1', $this->lastTo(self::CUSTOMER)['text']);
    }

    public function test_bot_accepts_when_no_ticket_could_be_sent(): void
    {
        $order = $this->order();
        $this->assertSame('placed', $order->status);

        $this->approveCustomerTemplates(CustomerTemplates::ACCEPTED);
        $this->travel(25)->hours();
        NotifyStaffOfOrder::dispatchSync($order->id);

        $this->assertSame('confirmed', $order->fresh()->status);
        // The customer wrote 25 hours ago: plain text would be dropped, so the approved template goes.
        $template = $this->lastTemplateTo(self::CUSTOMER);
        $this->assertSame('neotalab_c_order_accepted_en', $template['template']['name']);
        $this->assertSame(['Beirut Bites', '1'], array_slice(array_column($template['template']['components'][0]['parameters'], 'text'), 0, 2));
    }

    public function test_a_customer_out_of_the_window_without_an_approved_template_is_never_sent_plain_text(): void
    {
        $order = $this->order();
        $this->travel(25)->hours();
        NotifyStaffOfOrder::dispatchSync($order->id);

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertStringStartsWith('🧾 Order #1 sent', $this->lastTo(self::CUSTOMER)['text'], 'nothing new reached the customer');
        $this->assertNull($this->lastTemplateTo(self::CUSTOMER));
        $alert = SystemAlert::query()->where('message', 'like', '%was not told about "order_accepted"%')->first();
        $this->assertNotNull($alert, 'the owner is told, so the shop can write to the customer');
        $this->assertSame($this->merchant->id, $alert->merchant_id);
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

    public function test_staff_out_of_the_window_are_told_the_customer_cancelled(): void
    {
        $order = $this->order();
        $this->staffOutsideTheWindow(ShopTemplateService::CUSTOMER_CANCELLED);

        NotifyStaffOfCancellation::dispatchSync($order->id);

        $sent = $this->lastTemplateTo(self::STAFF);
        $this->assertSame(ShopTemplateService::CUSTOMER_CANCELLED, $sent['template']['name']);
        $this->assertSame(['1', 'Beirut Bites', '+96170123456'], array_column($sent['template']['components'][0]['parameters'], 'text'));
    }

    public function test_staff_out_of_the_window_without_the_template_are_not_sent_plain_text(): void
    {
        $order = $this->order();
        $this->staffOutsideTheWindow();

        NotifyStaffOfCancellation::dispatchSync($order->id);

        $this->assertNull($this->lastTemplateTo(self::STAFF));
        $this->assertStringNotContainsString('cancelled by the customer', $this->lastTo(self::STAFF)['text'] ?? '');
    }

    public function test_a_scheduled_order_reaches_staff_out_of_the_window_by_template(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => 'confirmed', 'deliver_at' => now()->addHour(), 'promised_at' => now()->addHour(), 'fulfillment' => 'pickup'])->save();
        $this->staffOutsideTheWindow(ShopTemplateService::SCHEDULED_DUE);

        app()->call([new StartScheduledOrder($order->id), 'handle']);

        $sent = $this->lastTemplateTo(self::STAFF);
        $this->assertSame(ShopTemplateService::SCHEDULED_DUE, $sent['template']['name']);
        $this->assertSame('1', $sent['template']['components'][0]['parameters'][0]['text']);
        $this->assertStringContainsString('is scheduled for', $this->lastTo(self::CUSTOMER)['text'] ?? '', 'the customer is reminded too (inside the window, as text)');
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

        $this->settings(['rating_delay_mins' => 0]);
        $order->forceFill(['status' => 'delivered', 'rating_requested_at' => null])->save();
        NotifyCustomerOfOrderStatus::dispatchSync($order->id);
        $this->assertSame('neotalab_c_order_delivered_en', $this->customerTemplate()[0], 'a shop that does not ask for ratings sends the plain thank-you');
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

    public function test_arabic_staff_get_the_arabic_template_and_english_is_the_fallback(): void
    {
        $this->merchant->update(['locale' => 'ar']);
        $order = $this->order();
        $this->staffOutsideTheWindow(ShopTemplateService::CUSTOMER_CANCELLED);

        NotifyStaffOfCancellation::dispatchSync($order->id);
        $this->assertSame(ShopTemplateService::CUSTOMER_CANCELLED, $this->lastTemplateTo(self::STAFF)['template']['name'], 'only English is approved: English goes out');

        $bot = $this->merchant->fresh()->settings['bot'];
        $bot['templates'][ShopTemplateService::CUSTOMER_CANCELLED.'_ar'] = ['status' => 'APPROVED'];
        $this->settings(['bot' => $bot]);
        NotifyStaffOfCancellation::dispatchSync($order->id);
        $sent = $this->lastTemplateTo(self::STAFF);
        $this->assertSame('neotalab_customer_cancelled_ar', $sent['template']['name']);
        $this->assertSame('ar', $sent['template']['language']['code']);
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

    public function test_a_photo_pauses_the_bot_and_alerts_the_staff(): void
    {
        $this->receive(['type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg']], self::CUSTOMER);

        $this->assertStringContainsString('we got your photo', $this->lastTo(self::CUSTOMER)['text']);
        $customer = Customer::query()->withoutGlobalScopes()->where('phone', self::CUSTOMER)->sole();
        $this->assertTrue($customer->bot_paused_until->isFuture());
        $alert = $this->lastTo(self::STAFF);
        $this->assertStringContainsString('sent a photo', $alert['text']);
        $this->assertSame(["stf:resume:{$customer->id}"], $alert['buttons']);

        // The bot does not answer the chat itself until the staff hand it back: the customer gets
        // one short note from the team, then nothing more for ten minutes (no flooding).
        $before = Http::recorded()->count();
        $this->receive(['type' => 'text', 'text' => ['body' => 'hello?']], self::CUSTOMER);
        $this->assertSame($before + 1, Http::recorded()->count());
        $this->assertStringContainsString('team member', $this->lastTo(self::CUSTOMER)['text']);

        $this->receive(['type' => 'sticker', 'sticker' => ['id' => 'st-1']], self::CUSTOMER);
        $this->assertSame($before + 1, Http::recorded()->count());
    }

    public function test_silent_staff_get_the_photo_alert_as_a_template(): void
    {
        $this->staffWroteAt(now()->subHours(30));
        $this->approveTemplates();

        $this->receive(['type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg']], self::CUSTOMER);

        $alert = $this->lastTemplateTo(self::STAFF);
        $this->assertSame(ShopTemplateService::STAFF_ALERT, $alert['template']['name']);
        $this->assertSame(['Beirut Bites', self::CUSTOMER.' sent a photo'], array_column($alert['template']['components'][0]['parameters'], 'text'));
    }

    public function test_a_photo_gets_the_menu_when_no_staff_can_be_alerted(): void
    {
        $this->staffWroteAt(now()->subHours(30));

        $this->receive(['type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg']], self::CUSTOMER);

        $this->assertStringContainsString("can't open photos", $this->lastTo(self::CUSTOMER)['text']);
        $this->assertNull(Customer::query()->withoutGlobalScopes()->where('phone', self::CUSTOMER)->value('bot_paused_until'));
    }
}
