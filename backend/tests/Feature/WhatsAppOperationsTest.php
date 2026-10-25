<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\SystemAlert;
use App\Services\BillingReminderService;
use App\Services\DailyReportService;
use App\Services\OrderService;
use App\Services\TrialExpiryService;
use App\Services\WhatsApp\PlatformTemplateService;
use App\Services\WhatsApp\ShopTemplateService;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\OrderMessage;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The shop runs entirely on WhatsApp: the bot takes and accepts orders, assigns drivers, and
 * drivers report pickup/delivery with buttons. The merchant only watches (and can step in).
 */
class WhatsAppOperationsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '+96170123456';

    private Merchant $merchant;

    private string $token;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Inside the default opening hours (09:00–23:00) whatever time the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
        // Delayed jobs would run instantly on the sync queue; tests run them explicitly instead.
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class]);

        [$this->merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Beirut Bites',
            'currency' => 'USD',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'lbp_rate' => 89500, 'prep_time_mins' => 25],
        ]);
        $this->token = $this->tokenFor($owner);

        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shawarma', 'price' => 6]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function receive(array $msg, string $from = self::CUSTOMER): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [$msg + ['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+')]],
        ]]]]]]);
    }

    private function say(string $text, string $from = self::CUSTOMER): void
    {
        $this->receive(['type' => 'text', 'text' => ['body' => $text]], $from);
    }

    private function tap(string $buttonId, string $from): void
    {
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $buttonId, 'title' => 'x']]], $from);
    }

    private function echo(string $to, string $text = 'Hi, this is the owner'): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['field' => 'smb_message_echoes', 'value' => [
            'metadata' => ['phone_number_id' => '555'],
            'message_echoes' => [['from' => '96171000000', 'to' => ltrim($to, '+'), 'id' => 'wamid.ECHO'.(++$this->seq), 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    private function order(string $from = self::CUSTOMER, string $confirm = 'confirm | | Bliss St, Building 4 | cash'): Order
    {
        $this->say('add 2 shawarma', $from);
        $this->say($confirm, $from);

        return Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->latest('number')->firstOrFail();
    }

    private function driver(string $name = 'Rami', string $wa = '+96176000001', string $status = 'on_duty', string $role = 'driver'): Driver
    {
        return Driver::query()->forceCreate([
            'merchant_id' => $this->merchant->id, 'name' => $name, 'role' => $role, 'phone' => $wa, 'whatsapp_number' => $wa,
            'password' => 'secret123', 'status' => $status, 'availability' => 'available',
            'shift_started_at' => $status === 'on_duty' ? now() : null,
        ]);
    }

    /** @return list<array{to: string, text: string, buttons: list<string>}> */
    private function sentTo(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)
            ->map(fn (Request $r) => [
                'text' => $r['text']['body'] ?? ($r['interactive']['body']['text'] ?? ''),
                'buttons' => collect($r['interactive']['action']['buttons'] ?? [])->pluck('reply.id')->all(),
            ])->values()->all();
    }

    private function lastTo(string $phone): string
    {
        $msgs = $this->sentTo($phone);

        return $msgs ? end($msgs)['text'] : '';
    }

    // ── auto-accept + auto-dispatch ─────────────────────────────────────────

    public function test_bot_accepts_and_assigns_a_free_driver(): void
    {
        $rami = $this->driver();
        $order = $this->order();

        $this->assertSame('confirmed', $order->status);
        $this->assertSame(25, $order->eta_mins);
        $this->assertSame($rami->id, $order->driver_id);
        $this->assertStringContainsString('confirmed — arriving around', $this->lastTo(self::CUSTOMER));

        $toRami = $this->sentTo($rami->whatsapp_number);
        $delivery = end($toRami);
        $this->assertStringContainsString('New delivery #1', $delivery['text']);
        $this->assertStringContainsString('Collect: 12.00$ (cash)', $delivery['text']);
        $this->assertSame(["drv:picked_up:{$order->id}", "drv:delivered:{$order->id}"], $delivery['buttons']);
    }

    public function test_driver_is_told_a_whish_order_is_paid_and_collects_nothing(): void
    {
        $this->driver();
        $order = $this->order();
        $order->forceFill(['payment_method' => 'whish', 'payment_status' => 'paid'])->save();

        $text = __('whatsapp.driver.assigned', OrderMessage::forDriver($order->fresh(), $this->merchant, 'en'), 'en');
        $this->assertStringContainsString('Paid with Whish — collect nothing', $text);
        $this->assertStringNotContainsString('12.00', $text);

        $ar = __('whatsapp.driver.on_the_way', OrderMessage::forDriver($order->fresh(), $this->merchant, 'ar'), 'ar');
        $this->assertStringContainsString('مدفوع عبر Whish', $ar);
    }

    public function test_driver_buttons_drive_the_order_to_delivered(): void
    {
        $rami = $this->driver();
        $order = $this->order();

        $this->tap("drv:picked_up:{$order->id}", $rami->whatsapp_number);
        $this->assertSame('picked_up', $order->fresh()->status);
        $this->assertStringStartsWith('🛵 Your order #1 is on its way with Rami — arriving around', $this->lastTo(self::CUSTOMER));

        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertStringContainsString('was delivered', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('How was it?', $this->lastTo(self::CUSTOMER), 'rating asked in the same message');
        $this->assertStringContainsString('Delivery #1 completed', $this->lastTo($rami->whatsapp_number));

        $rami->refresh();
        $this->assertNull($rami->active_order_id);
        $this->assertSame(1, $rami->total_deliveries);
    }

    public function test_driver_cannot_act_on_someone_elses_delivery(): void
    {
        $rami = $this->driver();
        $sami = $this->driver('Sami', '+96176000002');
        $order = $this->order();
        $this->assertSame($rami->id, $order->driver_id);

        $this->tap("drv:delivered:{$order->id}", $sami->whatsapp_number);

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertStringContainsString('not assigned to you', $this->lastTo($sami->whatsapp_number));
    }

    public function test_orders_wait_for_a_driver_and_are_handed_out_when_one_is_free(): void
    {
        $rami = $this->driver();
        $first = $this->order();
        $second = $this->order('+96170000002');

        $this->assertSame($rami->id, $first->driver_id);
        $this->assertNull($second->driver_id, 'no free driver yet');

        $this->tap("drv:delivered:{$first->id}", $rami->whatsapp_number);

        $this->assertSame($rami->id, $second->fresh()->driver_id);
    }

    public function test_driver_starting_a_shift_picks_up_waiting_orders(): void
    {
        $rami = $this->driver(status: 'off_duty');
        $order = $this->order();
        $this->assertNull($order->driver_id);

        $this->say('start', $rami->whatsapp_number);

        $this->assertSame('on_duty', $rami->fresh()->status);
        $this->assertSame($rami->id, $order->fresh()->driver_id);
        $this->assertStringContainsString('New delivery #1', $this->lastTo($rami->whatsapp_number));
    }

    public function test_the_driver_free_longest_gets_the_next_order(): void
    {
        $rami = $this->driver();
        $sami = $this->driver('Sami', '+96176000002');
        $rami->forceFill(['last_assigned_at' => now()->subMinutes(5)])->save();
        $sami->forceFill(['last_assigned_at' => now()->subMinutes(30)])->save();

        $this->assertSame($sami->id, $this->order()->driver_id);
    }

    public function test_drivers_never_reach_the_customer_bot(): void
    {
        $rami = $this->driver();

        $this->say('add 2 shawarma', $rami->whatsapp_number);

        $this->assertStringContainsString('Driver commands', $this->lastTo($rami->whatsapp_number));
        $this->assertSame(0, Customer::query()->withoutGlobalScopes()->where('phone', $rami->whatsapp_number)->count());
    }

    // ── shift summary + LBP ─────────────────────────────────────────────────

    public function test_stop_gives_the_cash_summary_in_usd_and_lbp(): void
    {
        $rami = $this->driver();

        $usd = $this->order();
        $this->tap("drv:delivered:{$usd->id}", $rami->whatsapp_number);
        $lbp = $this->order('+96170000002', 'confirm | | Hamra St 5 | cash | LBP');
        $this->tap("drv:delivered:{$lbp->id}", $rami->whatsapp_number);

        $this->say('stop', $rami->whatsapp_number);

        $summary = $this->lastTo($rami->whatsapp_number);
        $this->assertStringContainsString('Deliveries: 2', $summary);
        $this->assertStringContainsString('Cash to hand over: 12.00$ + 1,074,000 LBP', $summary);
        $this->assertSame('off_duty', $rami->fresh()->status);
    }

    public function test_removing_a_driver_hands_their_waiting_delivery_to_another(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->assertSame($rami->id, $order->driver_id);
        $karim = $this->driver('Karim', '+96176000002');

        $this->withToken($this->token)->deleteJson("/api/v1/drivers/{$rami->id}")->assertOk();

        $this->assertSame($karim->id, $order->fresh()->driver_id, 'the next free driver gets it');
        $this->assertNull($rami->fresh()->active_order_id);
        $this->assertSame($order->id, $karim->fresh()->active_order_id);
    }

    public function test_a_driver_on_the_road_cannot_be_removed_until_delivered(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:picked_up:{$order->id}", $rami->whatsapp_number);

        $this->withToken($this->token)->deleteJson("/api/v1/drivers/{$rami->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('driver');

        $this->assertTrue($rami->fresh()->is_active);
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->withToken($this->token)->deleteJson("/api/v1/drivers/{$rami->id}")->assertOk();
    }

    public function test_driver_cannot_deliver_so_the_order_closes_and_the_shop_is_told(): void
    {
        $owner = $this->staff();
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("stf:accept:{$order->id}", $owner->whatsapp_number);
        $this->tap("drv:picked_up:{$order->id}", $rami->whatsapp_number);

        $this->tap("drv:failed:{$order->id}", $rami->whatsapp_number);

        $order->refresh();
        $this->assertSame('failed', $order->status);
        $this->assertSame('Customer not reachable', $order->cancel_reason);
        $this->assertNull($rami->fresh()->active_order_id, 'the driver is free again');
        $this->assertStringContainsString('closed as not delivered', $this->lastTo($rami->whatsapp_number));
        $this->assertStringContainsString("#{$order->number} was not delivered", $this->lastTo($owner->whatsapp_number));
    }

    public function test_a_driver_cannot_mark_an_order_failed_before_picking_it_up(): void
    {
        $rami = $this->driver();
        $order = $this->order();

        $this->tap("drv:failed:{$order->id}", $rami->whatsapp_number);

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_staff_closes_a_refund_by_typing_refunded_and_the_number(): void
    {
        $lina = $this->driver('Lina', '+96176000008', 'off_duty', 'staff');
        $order = $this->order();
        $order->forceFill(['status' => 'cancelled', 'payment_status' => 'refund_due'])->save();

        $this->receive(['type' => 'text', 'text' => ['body' => "refunded {$order->number}"]], $lina->whatsapp_number);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertStringContainsString('marked as refunded', $this->lastTo($lina->whatsapp_number));

        $this->receive(['type' => 'text', 'text' => ['body' => "refunded {$order->number}"]], $lina->whatsapp_number);
        $this->assertStringContainsString('No refund is waiting', $this->lastTo($lina->whatsapp_number), 'a second time changes nothing');
    }

    public function test_staff_cannot_mark_a_cancelled_booking_as_done(): void
    {
        $lina = $this->driver('Lina', '+96176000009', 'off_duty', 'staff');
        $customer = Customer::query()->withoutGlobalScopes()->forceCreate(['merchant_id' => $this->merchant->id, 'phone' => '+96170000077']);
        $booking = Appointment::query()->withoutGlobalScopes()->forceCreate([
            'merchant_id' => $this->merchant->id, 'customer_id' => $customer->id, 'service_name' => 'Haircut', 'price' => 10,
            'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2), 'status' => 'cancelled',
        ]);

        $this->tap("stf:appt_completed:{$booking->id}", $lina->whatsapp_number);

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertStringContainsString('already cancelled or closed', $this->lastTo($lina->whatsapp_number));
    }

    public function test_driver_cannot_stop_with_a_delivery_in_progress(): void
    {
        $rami = $this->driver();
        $this->order();

        $this->say('stop', $rami->whatsapp_number);

        $this->assertStringContainsString('still have delivery #1', $this->lastTo($rami->whatsapp_number));
        $this->assertSame('on_duty', $rami->fresh()->status);
    }

    public function test_lbp_total_uses_the_shop_rate_rounded_up_to_1000(): void
    {
        $settings = $this->merchant->settings;
        $settings['lbp_rate'] = 89700;
        $this->merchant->update(['settings' => $settings]);

        $order = $this->order(confirm: 'confirm | | Bliss St, Building 4 | cash | LBP');

        // 12 × 89,700 = 1,076,400 → 1,077,000
        $this->assertEquals(1077000, (float) $order->total_lbp);
        $this->assertSame('LBP', $order->payment_currency);
        $this->assertStringContainsString('12.00$ (1,077,000 LBP)', $this->lastTo(self::CUSTOMER));
    }

    public function test_lbp_is_ignored_when_the_shop_has_no_rate(): void
    {
        $settings = $this->merchant->settings;
        unset($settings['lbp_rate']);
        $this->merchant->update(['settings' => $settings]);

        $order = $this->order(confirm: 'confirm | | Bliss St, Building 4 | cash | LBP');

        $this->assertSame('USD', $order->payment_currency);
        $this->assertNull($order->total_lbp);
    }

    // ── location pin ────────────────────────────────────────────────────────

    public function test_location_pin_replaces_the_written_address_and_reaches_the_driver(): void
    {
        $rami = $this->driver();

        $this->say('add 1 shawarma');
        $this->receive(['type' => 'location', 'location' => ['latitude' => 33.8959, 'longitude' => 35.4784, 'name' => 'AUB Main Gate']]);
        $this->say('confirm');

        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->sole();
        $this->assertEqualsWithDelta(33.8959, $order->delivery_lat, 0.0001);
        $this->assertEqualsWithDelta(35.4784, $order->delivery_lng, 0.0001);
        $pin = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => $r['to'] === preg_replace('/\D+/', '', $rami->whatsapp_number) && $r['type'] === 'location');
        $this->assertEquals(33.8959, $pin['location']['latitude']);
    }

    // ── late alert ──────────────────────────────────────────────────────────

    public function test_late_order_gets_an_apology_once(): void
    {
        $order = $this->order();
        Bus::assertDispatched(CheckLateOrder::class, fn (CheckLateOrder $job) => $job->orderId === $order->id);

        $this->travel(2)->hours();
        app()->call([new CheckLateOrder($order->id), 'handle']);
        app()->call([new CheckLateOrder($order->id), 'handle']); // new time is in the future: nothing more

        $this->assertStringContainsString('new time: arriving around', $this->lastTo(self::CUSTOMER));
        $apologies = array_filter($this->sentTo(self::CUSTOMER), fn ($m) => str_contains($m['text'], 'taking a little longer'));
        $this->assertCount(1, $apologies);
    }

    public function test_delivered_order_gets_no_late_apology(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->travel(2)->hours();
        app()->call([new CheckLateOrder($order->id), 'handle']);

        $this->assertStringNotContainsString('taking a little longer', $this->lastTo(self::CUSTOMER));
    }

    public function test_late_alert_can_be_switched_off(): void
    {
        $settings = $this->merchant->settings;
        $settings['late_alert_mins'] = 0;
        $this->merchant->update(['settings' => $settings]);

        $this->order();

        Bus::assertNotDispatched(CheckLateOrder::class);
    }

    // ── rating ──────────────────────────────────────────────────────────────

    public function test_customer_rates_a_delivered_order(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->say('5');

        $this->assertSame(5, $order->fresh()->rating);
        $this->assertSame('Thank you for your rating! 🙏', $this->lastTo(self::CUSTOMER));
    }

    public function test_a_number_typed_in_a_new_order_after_the_rating_question_is_not_a_rating(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);
        $shawarma = Product::query()->withoutGlobalScopes()->where('name', 'Shawarma')->firstOrFail();

        $this->travel(1)->minutes();
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => "mn:item:{$shawarma->id}", 'title' => 'x']]]);
        $this->say('2'); // answering "how many?"

        $this->assertNull($order->fresh()->rating);
        $this->assertStringNotContainsString('not perfect', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('Added 2× Shawarma', $this->lastTo(self::CUSTOMER), 'the typed 2 is the quantity');
    }

    public function test_low_rating_in_arabic_digits_gets_an_apology(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->say('٢');

        $this->assertSame(2, $order->fresh()->rating);
        $this->assertStringContainsString('sorry it was not perfect', $this->lastTo(self::CUSTOMER));
    }

    public function test_a_low_rating_alerts_the_staff_with_the_order_and_customer(): void
    {
        $owner = $this->staff();
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("stf:accept:{$order->id}", $owner->whatsapp_number);
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->say('2');

        $alert = $this->lastTo($owner->whatsapp_number);
        $this->assertStringContainsString("Order #{$order->number} was rated 2/5", $alert);
        $this->assertStringContainsString(self::CUSTOMER, $alert);
        $this->assertStringContainsString('Beirut Bites will look into it', $this->lastTo(self::CUSTOMER));
    }

    public function test_a_low_rating_goes_to_the_report_number_when_no_staff_can_be_reached(): void
    {
        $this->ownerNumber();
        $this->driver('Rana', '+96171555111', 'on_duty', 'staff'); // never wrote to the shop number
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);
        $this->staffWroteToShop($this->merchant, '+96171222333');

        $this->say('1');

        $this->assertSame([], $this->sentTo('+96171555111'));
        $this->assertStringContainsString("Order #{$order->number} was rated 1/5", $this->lastTo('+96171222333'));
        $this->assertStringContainsString('will look into it', $this->lastTo(self::CUSTOMER));
    }

    public function test_a_low_rating_promises_nothing_when_nobody_at_the_shop_can_be_told(): void
    {
        $this->ownerNumber(); // the owner never wrote to the shop number
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->say('3');

        $this->assertSame([], $this->requestsTo('+96171222333'));
        $this->assertSame('Thank you for telling us, and sorry it was not perfect. 🙏', $this->lastTo(self::CUSTOMER));
    }

    public function test_a_number_is_not_a_rating_unless_one_was_asked_for(): void
    {
        $this->say('add 2 shawarma');
        $this->say('3');

        $this->assertStringNotContainsString('rating', $this->lastTo(self::CUSTOMER));
    }

    // ── merchant takes over / bot off ───────────────────────────────────────

    public function test_merchant_replying_does_not_silence_the_bot(): void
    {
        $this->say('hello');
        $before = count($this->sentTo(self::CUSTOMER));

        $this->echo(self::CUSTOMER, 'Welcome! Our special today is shawarma.');
        $this->say('menu');

        $this->assertCount($before + 1, $this->sentTo(self::CUSTOMER));
    }

    public function test_merchant_pauses_and_resumes_the_bot_from_the_chat(): void
    {
        $this->say('hello');
        $before = count($this->sentTo(self::CUSTOMER));

        $this->echo(self::CUSTOMER, '⏸');
        $this->say('can I get a discount?');
        $this->assertCount($before + 1, $this->sentTo(self::CUSTOMER), 'paused: one note from the team, the merchant answers');
        $this->assertStringContainsString('team member', $this->lastTo(self::CUSTOMER));

        $this->echo(self::CUSTOMER, '▶️');
        $this->say('ok thanks, add 1 shawarma');
        $this->assertCount($before + 2, $this->sentTo(self::CUSTOMER), 'resumed immediately: the team note, then the bot answers');
    }

    public function test_a_forgotten_pause_expires(): void
    {
        $this->echo(self::CUSTOMER, '⏸');
        $this->travel(61)->minutes();

        $this->say('hello');

        $this->assertCount(1, $this->sentTo(self::CUSTOMER));
    }

    // ── cancel, human, split cash ───────────────────────────────────────────

    public function test_customer_cancels_before_pickup_and_the_driver_is_told(): void
    {
        $rami = $this->driver();
        $order = $this->order();

        $this->say('cancel order');
        $this->tap("cx:yes:{$order->id}", self::CUSTOMER); // cancelling needs the customer's Yes

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('customer', $order->fresh()->cancelled_by);
        $this->assertStringContainsString('was cancelled', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('cancelled by the customer', $this->lastTo($rami->whatsapp_number));
        $this->assertNull($rami->fresh()->active_order_id);
    }

    public function test_cancel_asks_yes_or_no_and_no_keeps_the_order(): void
    {
        $order = $this->order();
        $before = $order->fresh()->status;

        $this->say('cancel order');
        $asked = $this->sentTo(self::CUSTOMER);
        $last = end($asked);
        $this->assertStringContainsString("cancel order #{$order->number}", $last['text']);
        $this->assertSame(["cx:yes:{$order->id}", "cx:no:{$order->id}"], $last['buttons'], 'the buttons decide, not the assistant');
        $this->assertSame($before, $order->fresh()->status, 'nothing is cancelled until the customer taps Yes');

        $this->tap("cx:no:{$order->id}", self::CUSTOMER);
        $this->assertSame($before, $order->fresh()->status, 'No keeps the order');
        $this->assertStringContainsString("#{$order->number} kept", $this->lastTo(self::CUSTOMER));
    }

    public function test_any_wording_of_a_cancel_request_gets_the_yes_or_no_question(): void
    {
        $order = $this->order();

        $this->say('أريد إلغاء الطلب من فضلك');
        $asked = $this->sentTo(self::CUSTOMER);
        $this->assertSame(["cx:yes:{$order->id}", "cx:no:{$order->id}"], end($asked)['buttons'], 'the question does not depend on the assistant wording');
    }

    public function test_a_typed_yes_without_the_question_does_not_cancel_or_hand_over(): void
    {
        $order = $this->order();
        $before = $order->fresh()->status;

        $this->say('yes, cancel it');

        $this->assertSame($before, $order->fresh()->status, 'a typed yes alone does not cancel');
        $this->assertNull(Customer::query()->withoutGlobalScopes()->where('phone', self::CUSTOMER)->value('bot_paused_until'), 'and it does not hand the chat to the shop');
    }

    public function test_cancelling_an_order_already_in_the_works_hands_the_chat_to_the_shop(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        app(OrderService::class)->transition($this->merchant, $order->id, 'preparing');

        $this->say('cancel order');

        $this->assertSame('preparing', $order->fresh()->status, 'the food is not wasted');
        $this->assertStringContainsString("Order #{$order->number} is already being prepared", $this->lastTo(self::CUSTOMER));
        $this->assertNotNull(Customer::query()->withoutGlobalScopes()->where('phone', self::CUSTOMER)->value('bot_paused_until'));
        $this->assertSame($order->id, $rami->fresh()->active_order_id);

        $before = count($this->sentTo(self::CUSTOMER));
        $this->say('hello?');
        $this->assertCount($before + 1, $this->sentTo(self::CUSTOMER), 'the shop answers, the bot only adds one note');
    }

    public function test_customer_cannot_cancel_once_the_driver_has_it(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:picked_up:{$order->id}", $rami->whatsapp_number);

        $this->say('cancel order');

        $this->assertSame('picked_up', $order->fresh()->status);
        $this->assertStringContainsString('already on its way', $this->lastTo(self::CUSTOMER));
    }

    public function test_asking_for_a_human_pauses_the_bot_in_that_chat(): void
    {
        $this->say('I want to talk to a human');
        $this->assertStringContainsString('team will reply to you here shortly', $this->lastTo(self::CUSTOMER));
        $before = count($this->sentTo(self::CUSTOMER));

        $this->say('hello?');
        $this->assertCount($before + 1, $this->sentTo(self::CUSTOMER), 'one team note, then the bot stays quiet');

        $this->echo(self::CUSTOMER, '▶️');
        $this->say('menu');
        $this->assertCount($before + 2, $this->sentTo(self::CUSTOMER));
    }

    public function test_cash_can_be_split_between_usd_and_lbp(): void
    {
        $rami = $this->driver();
        $order = $this->order(confirm: 'confirm | | Bliss St, Building 4 | cash | MIXED | 5');

        // 12 USD total: 5 USD cash + 7 × 89,500 = 626,500 → 627,000 LBP
        $this->assertSame('MIXED', $order->payment_currency);
        $this->assertEquals(5.0, (float) $order->cash_main);
        $this->assertEquals(627000, (float) $order->total_lbp);
        $this->assertStringContainsString('you pay 5.00$ + 627,000 LBP', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('Collect: 5.00$ + 627,000 LBP (cash)', $this->lastTo($rami->whatsapp_number));

        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);
        $this->say('stop', $rami->whatsapp_number);
        $this->assertStringContainsString('Cash to hand over: 5.00$ + 627,000 LBP', $this->lastTo($rami->whatsapp_number));
    }

    public function test_bot_off_means_no_automatic_replies(): void
    {
        $settings = $this->merchant->settings;
        $settings['mode'] = 'manual';
        $this->merchant->update(['settings' => $settings]);

        $this->say('hello');

        $this->assertSame([], $this->sentTo(self::CUSTOMER));
    }

    // ── dashboard settings ──────────────────────────────────────────────────

    public function test_merchant_saves_operational_settings(): void
    {
        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', [
            'prep_time_mins' => 40, 'late_alert_mins' => 10, 'lbp_rate' => 90000,
            'report_whatsapp' => '+96171222333', 'report_hour' => 22,
        ])->assertOk()
            ->assertJsonPath('profile.prep_time_mins', 40)
            ->assertJsonPath('profile.lbp_rate', 90000)
            ->assertJsonPath('profile.report_whatsapp', '+96171222333');

        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['prep_time_mins' => 0])
            ->assertStatus(422);
    }

    // ── owner messages from the shop's own number ───────────────────────────

    private function ownerNumber(): void
    {
        $settings = $this->merchant->settings;
        $settings['report_whatsapp'] = '+96171222333';
        $settings['report_hour'] = 23;
        $settings['bot']['waba_id'] = 'waba-1';
        $this->merchant->update(['settings' => $settings, 'timezone' => 'Asia/Beirut']);
    }

    private function approveTemplates(): void
    {
        $settings = $this->merchant->fresh()->settings;
        $settings['bot']['templates'] = [
            ShopTemplateService::BILLING_REMINDER => ['id' => '2', 'status' => 'APPROVED'],
        ];
        $this->merchant->update(['settings' => $settings]);
    }

    /** @return list<Request> */
    private function requestsTo(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)
            ->values()->all();
    }

    /** The platform (NeoTalab's own) number sends the report; the platform template is approved. */
    private function platformReports(bool $approved = true): void
    {
        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], [
            'wa_phone_id' => '999888777',
            'wa_templates' => ['neotalab_daily_report' => $approved ? 'APPROVED' : 'PENDING'],
        ]);
        $platform->save();
    }

    public function test_owner_gets_the_nightly_report_from_the_platform_number_once(): void
    {
        $this->ownerNumber();
        $this->platformReports();
        $rami = $this->driver();
        Carbon::setTestNow(Carbon::parse('2026-10-06 20:00', 'Asia/Beirut'));
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $reports = app(DailyReportService::class);
        Carbon::setTestNow(Carbon::parse('2026-10-06 22:00', 'Asia/Beirut'));
        $this->assertSame(0, $reports->sendDue(), 'not the report hour yet');

        Carbon::setTestNow(Carbon::parse('2026-10-06 23:00', 'Asia/Beirut'));
        $this->assertSame(1, $reports->sendDue());
        $this->assertSame(0, $reports->sendDue(), 'only once per day');

        $sent = $this->requestsTo('+96171222333');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('/999888777/messages', $sent[0]->url(), 'sent from NeoTalab\'s number, not the shop\'s');
        $this->assertSame(PlatformTemplateService::DAILY_REPORT, $sent[0]['template']['name']);
        $params = collect($sent[0]['template']['components'][0]['parameters'])->pluck('text')->all();
        $this->assertSame('Beirut Bites', $params[0]);
        $this->assertSame('1', $params[2], 'orders delivered');
        $this->assertSame('12.00$', $params[3], 'sales');
        $this->assertStringContainsString('Shawarma ×2', $params[6]);
    }

    public function test_report_is_not_sent_until_meta_approves_the_platform_template(): void
    {
        $this->ownerNumber();
        $this->platformReports(approved: false);

        $sent = app(DailyReportService::class)->sendDue(Carbon::parse('2026-10-06 23:05', 'Asia/Beirut'));

        $this->assertSame(0, $sent);
        $this->assertSame([], $this->requestsTo('+96171222333'));
        $this->assertNull(($this->merchant->fresh()->settings)['report_last_sent'] ?? null);
        $this->assertStringStartsWith('Daily report not sent', SystemAlert::query()->latest()->value('message') ?? 'Daily report not sent');
    }

    public function test_billing_reminder_needs_the_template_or_the_24_hour_window(): void
    {
        $this->ownerNumber();
        $this->merchant->update(['subscription_ends_at' => Carbon::parse('2026-10-09 12:00', 'Asia/Beirut')]);
        $reminders = app(BillingReminderService::class);

        $this->assertSame(0, $reminders->sendDue(Carbon::parse('2026-10-06 10:00', 'Asia/Beirut')));
        $this->assertSame([], $this->requestsTo('+96171222333'));
        $this->assertDatabaseHas('system_alerts', ['level' => 'warning', 'merchant_id' => $this->merchant->id]);

        $this->staffWroteToShop($this->merchant, '+96171222333', Carbon::parse('2026-10-07 09:00', 'Asia/Beirut'));
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'Asia/Beirut'));
        $this->assertSame(1, $reminders->sendDue(), 'inside the window plain text arrives');
        $this->assertStringContainsString('2026-10-09', $this->requestsTo('+96171222333')[0]['text']['body']);
    }

    public function test_the_report_number_cannot_be_the_shops_own_number(): void
    {
        $settings = $this->merchant->settings;
        $settings['bot']['display_phone_number'] = '+961 71 444 555';
        $settings['ops_whatsapp'] = '+96171666777';
        $this->merchant->update(['settings' => $settings]);

        foreach (['71 444 555', '+96171666777'] as $own) {
            $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['report_whatsapp' => $own])
                ->assertStatus(422)->assertJsonValidationErrors('report_whatsapp');
        }

        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['report_whatsapp' => '71 222 333'])
            ->assertOk()->assertJsonPath('profile.report_whatsapp', '+96171222333');
    }

    public function test_owner_is_reminded_before_the_subscription_ends(): void
    {
        $this->ownerNumber();
        $this->approveTemplates();
        $this->merchant->update(['subscription_ends_at' => Carbon::parse('2026-10-09 12:00', 'Asia/Beirut')]);
        $reminders = app(BillingReminderService::class);

        $this->assertSame(0, $reminders->sendDue(Carbon::parse('2026-10-05 10:00', 'Asia/Beirut')), '4 days before: too early');
        $this->assertSame(0, $reminders->sendDue(Carbon::parse('2026-10-06 09:00', 'Asia/Beirut')), 'not 10:00 yet');
        $this->assertSame(1, $reminders->sendDue(Carbon::parse('2026-10-06 10:00', 'Asia/Beirut')));
        $this->assertSame(0, $reminders->sendDue(Carbon::parse('2026-10-06 10:30', 'Asia/Beirut')), 'once a day');
        $this->assertSame(1, $reminders->sendDue(Carbon::parse('2026-10-07 10:00', 'Asia/Beirut')), 'again the next day');

        $sent = $this->requestsTo('+96171222333')[0];
        $this->assertSame(ShopTemplateService::BILLING_REMINDER, $sent['template']['name']);
        $this->assertSame('2026-10-09', $sent['template']['components'][0]['parameters'][1]['text']);
    }

    public function test_a_trial_shop_is_reminded_only_on_its_last_day(): void
    {
        $this->ownerNumber();
        $this->approveTemplates();
        $this->merchant->update([
            'trial_ends_at' => Carbon::parse('2026-10-09 12:00', 'Asia/Beirut'),
            'settings' => array_merge($this->merchant->fresh()->settings, ['subscription_status' => 'trial']),
        ]);
        $reminders = app(BillingReminderService::class);

        $this->assertSame(0, $reminders->sendDue(Carbon::parse('2026-10-07 10:00', 'Asia/Beirut')), 'a 3-day trial is not nagged on day one');
        $this->assertSame(1, $reminders->sendDue(Carbon::parse('2026-10-08 10:00', 'Asia/Beirut')), 'the day before it ends');
        $this->assertSame(1, $reminders->sendDue(Carbon::parse('2026-10-09 10:00', 'Asia/Beirut')), 'the last day');
    }

    public function test_an_unpaid_trial_stops_by_itself_when_it_ends(): void
    {
        $this->ownerNumber();
        $this->merchant->update([
            'trial_ends_at' => Carbon::parse('2026-10-09 12:00', 'Asia/Beirut'),
            'settings' => array_merge($this->merchant->fresh()->settings, ['subscription_status' => 'trial']),
        ]);
        $trials = app(TrialExpiryService::class);

        $this->assertSame(0, $trials->expireDue(Carbon::parse('2026-10-09 11:59', 'Asia/Beirut')), 'still in the trial');
        $this->assertSame(1, $trials->expireDue(Carbon::parse('2026-10-09 12:00', 'Asia/Beirut')));
        $this->assertSame(0, $trials->expireDue(Carbon::parse('2026-10-09 13:00', 'Asia/Beirut')), 'only once');

        $merchant = $this->merchant->fresh();
        $this->assertSame('suspended', $merchant->status);
        $this->assertSame('pending', $merchant->settings['subscription_status']);
        $this->assertStringContainsString('free trial', $this->requestsTo('+96171222333')[0]['text']['body']);
        $this->assertNull(MerchantWhatsAppCredentials::findMerchantByPhoneId('555'), 'its number stops answering customers');
    }

    public function test_a_paid_shop_is_never_stopped_by_the_trial_check(): void
    {
        $this->merchant->update([
            'trial_ends_at' => now()->subDay(),
            'settings' => array_merge($this->merchant->fresh()->settings, ['subscription_status' => 'paid']),
        ]);

        $this->assertSame(0, app(TrialExpiryService::class)->expireDue());
        $this->assertSame('active', $this->merchant->fresh()->status);
    }

    public function test_templates_are_submitted_to_the_shop_and_statuses_refreshed(): void
    {
        $this->ownerNumber();
        config(['whatsapp.graph_api_base' => 'https://meta.test']);
        Http::fake([
            'meta.test/*/waba-1/message_templates?*' => Http::response(['data' => []], 200),
            'meta.test/*/waba-1/message_templates' => Http::response(['id' => 'tpl-1', 'status' => 'PENDING'], 200),
        ]);

        app(ShopTemplateService::class)->sync($this->merchant->fresh());

        $submitted = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/waba-1/message_templates'))
            ->map(fn (Request $r) => $r['name'].':'.$r['category'])->values()->all();
        $this->assertSame([
            ShopTemplateService::BILLING_REMINDER.':UTILITY',
            ShopTemplateService::NEW_ORDER.':UTILITY', ShopTemplateService::STAFF_ALERT.':UTILITY',
            ShopTemplateService::NEW_BOOKING.':UTILITY', ShopTemplateService::ORDER_DUE.':UTILITY', ShopTemplateService::REFUND_NEEDED.':UTILITY',
        ], $submitted);
        $newOrder = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => $r->method() === 'POST' && ($r['name'] ?? null) === ShopTemplateService::NEW_ORDER);
        $this->assertSame(['Accept', "Can't take it"], array_column($newOrder['components'][1]['buttons'], 'text'));
        $this->assertSame('PENDING', $this->merchant->fresh()->settings['bot']['templates'][ShopTemplateService::NEW_ORDER]['status']);

        app(ShopTemplateService::class)->sync($this->merchant->fresh());
        $this->assertFalse(app(ShopTemplateService::class)->isApproved($this->merchant->fresh(), 'neotalab_daily_report'), 'the report is no longer a shop template');
    }

    // ── pickup shops + staff numbers ────────────────────────────────────────

    /** A staff member who wrote to the shop number today (inside WhatsApp's 24-hour window). */
    private function staff(): Driver
    {
        $staff = $this->driver('Owner', '+96171555000', 'on_duty', 'staff');
        $this->staffWroteToShop($this->merchant, '+96171555000');

        return $staff;
    }

    public function test_team_page_shows_which_staff_new_orders_cannot_reach(): void
    {
        $this->staff(); // wrote to the shop number
        $this->driver('Rana', '+96171555111', 'off_duty', 'staff');
        $this->driver('Old', '+96171555222', 'off_duty', 'staff');
        $this->staffWroteToShop($this->merchant, '+96171555222', now()->subDays(2));

        $reachable = fn () => collect($this->withToken($this->token)->getJson('/api/v1/drivers')->assertOk()->json('drivers'))
            ->pluck('reachable', 'name')->all();

        $this->assertSame(['Old' => false, 'Owner' => true, 'Rana' => false], $reachable());

        $settings = $this->merchant->fresh()->settings;
        $settings['bot']['templates'] = [ShopTemplateService::NEW_ORDER => ['id' => '3', 'status' => 'APPROVED']];
        $this->merchant->update(['settings' => $settings]);
        $this->app['auth']->forgetGuards(); // the next request loads the shop afresh
        $this->assertSame(['Old' => true, 'Owner' => true, 'Rana' => true], $reachable(), 'the approved template reaches everyone');
    }

    private function shopSettings(array $values): void
    {
        $this->merchant->update(['settings' => array_merge($this->merchant->fresh()->settings, $values)]);
    }

    public function test_pickup_shop_takes_orders_without_address_or_driver(): void
    {
        $this->shopSettings(['fulfillment' => 'pickup', 'pickup_address' => 'Hamra St, next to the bakery']);
        $rami = $this->driver();

        $order = $this->order(confirm: 'confirm');

        $this->assertSame('pickup', $order->fulfillment);
        $this->assertNull($order->driver_id, 'pickup orders never go to a driver');
        $this->assertEquals(0.0, (float) $order->delivery_fee);
        $this->assertStringContainsString('ready for pickup around', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('at Hamra St, next to the bakery', $this->lastTo(self::CUSTOMER));
        $this->assertSame([], $this->sentTo($rami->whatsapp_number));
    }

    public function test_shop_offering_both_asks_and_respects_the_choice(): void
    {
        $this->shopSettings(['fulfillment' => 'both']);

        $this->say('add 1 shawarma');
        $this->say('confirm');
        $asked = $this->sentTo(self::CUSTOMER);
        $ask = end($asked);
        $this->assertStringContainsString('How would you like', $ask['text'], 'the buttons, not a plain question');
        $this->assertSame(['co:ful:delivery', 'co:ful:pickup'], $ask['buttons']);

        $this->say('confirm | | | cash | | | pickup');
        $this->assertSame('pickup', Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->sole()->fulfillment);
    }

    public function test_typed_delivery_or_pickup_answers_the_choice_without_buttons(): void
    {
        $this->shopSettings(['fulfillment' => 'both']);

        $this->say('add 1 shawarma');
        $this->say('confirm');

        // A short answer the bot does not know gets the buttons again, not a dead end.
        $this->say('hmm maybe');
        $asked = $this->sentTo(self::CUSTOMER);
        $this->assertStringContainsString('How would you like', end($asked)['text']);
        $this->assertSame(['co:ful:delivery', 'co:ful:pickup'], end($asked)['buttons']);

        // A typed "delivery" moves on to the next question (the address), without the assistant.
        $this->say('delivery');
        $this->assertStringNotContainsString('understand', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('type your address', $this->lastTo(self::CUSTOMER));
    }

    public function test_staff_get_pickup_orders_and_close_them_with_buttons(): void
    {
        $this->shopSettings(['fulfillment' => 'pickup', 'pickup_address' => 'Hamra St']);
        $owner = $this->staff();
        $order = $this->order(confirm: 'confirm');

        // With staff, the shop accepts each order first.
        $ticket = collect($this->sentTo($owner->whatsapp_number))->last();
        $this->assertStringContainsString('New PICKUP order #1', $ticket['text']);
        $this->assertSame(["stf:accept:{$order->id}", "stf:reject:{$order->id}"], $ticket['buttons']);
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertStringContainsString('sent to', $this->lastTo(self::CUSTOMER));

        $this->tap("stf:accept:{$order->id}", $owner->whatsapp_number);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertStringContainsString('accepted your order #1 — ready for pickup around', $this->lastTo(self::CUSTOMER));
        $goAhead = collect($this->sentTo($owner->whatsapp_number))->last()['text'];
        $this->assertStringStartsWith('✅ Order #1 is confirmed — prepare it now.', $goAhead);
        $this->assertStringContainsString('(💵 cash)', $goAhead);
        $this->assertStringContainsString('The customer picks it up at the shop.', $goAhead);

        $card = collect($this->sentTo($owner->whatsapp_number))->last();
        $this->assertSame(["stf:ready:{$order->id}", "stf:delivered:{$order->id}"], $card['buttons']);

        $this->tap("stf:ready:{$order->id}", $owner->whatsapp_number);
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertStringContainsString('ready! You can pick it up now at Hamra St', $this->lastTo(self::CUSTOMER));

        $this->tap("stf:delivered:{$order->id}", $owner->whatsapp_number);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertStringContainsString('Thank you for picking up order #1', $this->lastTo(self::CUSTOMER));
    }

    public function test_shop_delivering_itself_closes_orders_from_the_staff_phone(): void
    {
        $owner = $this->staff();
        $order = $this->order();

        $this->tap("stf:accept:{$order->id}", $owner->whatsapp_number);
        $this->assertNull($order->fresh()->driver_id, 'staff are never auto-assigned as drivers');
        $this->assertSame(["stf:picked_up:{$order->id}", "stf:delivered:{$order->id}"], collect($this->sentTo($owner->whatsapp_number))->last()['buttons']);

        $this->tap("stf:picked_up:{$order->id}", $owner->whatsapp_number);
        $this->assertSame('🛵 Your order #1 is on its way!', $this->lastTo(self::CUSTOMER));

        $this->tap("stf:delivered:{$order->id}", $owner->whatsapp_number);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_staff_open_and_close_the_shop(): void
    {
        $owner = $this->staff();

        $this->say('close', $owner->whatsapp_number);
        $this->assertSame('closed', $this->merchant->fresh()->settings['mode']);
        $this->say('hello');
        $this->assertStringContainsString('currently closed', $this->lastTo(self::CUSTOMER));

        $this->say('open', $owner->whatsapp_number);
        $this->assertSame('auto', $this->merchant->fresh()->settings['mode']);
        $this->assertStringContainsString('is OPEN', $this->lastTo($owner->whatsapp_number));
    }

    public function test_staff_are_told_when_a_customer_wants_a_person_and_can_hand_back(): void
    {
        $owner = $this->staff();
        $this->say('human please');

        $alert = collect($this->sentTo($owner->whatsapp_number))->last();
        $this->assertStringContainsString('asked to talk to a person', $alert['text']);
        $customer = Customer::query()->withoutGlobalScopes()->where('phone', self::CUSTOMER)->sole();
        $this->assertSame(["stf:resume:{$customer->id}"], $alert['buttons']);

        $this->tap("stf:resume:{$customer->id}", $owner->whatsapp_number);
        $before = count($this->sentTo(self::CUSTOMER));
        $this->say('menu');
        $this->assertCount($before + 1, $this->sentTo(self::CUSTOMER));
    }
}
