<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\NotifyShopOfOrder;
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
use App\Services\WhatsApp\CustomerTemplates;
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
    use InteractsWithTenants, RefreshDatabase, \Tests\Concerns\ShopPhone;

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
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class, NotifyShopOfOrder::class]);

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
        if (str_starts_with($buttonId, 'stf:')) {
            $this->shopTap($buttonId);

            return;
        }

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
        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->latest('number')->firstOrFail();
        if ($order->status === 'placed' && $order->payment_method !== 'whish') {
            $this->shopTypes($from, '✅'); // the shop accepts from its phone
        }

        return $order->fresh();
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
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits && ($r['type'] ?? '') !== 'location')
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

    // ── accept + auto-dispatch ─────────────────────────────────────────────

    public function test_bot_accepts_and_assigns_a_free_driver(): void
    {
        $rami = $this->driver();
        $order = $this->order();

        $this->assertSame('confirmed', $order->status);
        $this->assertSame(25, $order->eta_mins);
        $this->assertSame($rami->id, $order->driver_id);
        $this->assertStringContainsString('accepted order #1 — preparing now, ready around', $this->lastTo(self::CUSTOMER));

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
        $this->assertStringStartsWith('🛵 Hey! It’s Rami — your order #1 is on the way', $this->lastTo(self::CUSTOMER));

        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertStringContainsString('is with you', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('How was your order?', $this->lastTo(self::CUSTOMER), 'rating asked in the same message');
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
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:picked_up:{$order->id}", $rami->whatsapp_number);

        $this->tap("drv:failed:{$order->id}", $rami->whatsapp_number);

        $order->refresh();
        $this->assertSame('failed', $order->status);
        $this->assertSame('Customer not reachable', $order->cancel_reason);
        $this->assertNull($rami->fresh()->active_order_id, 'the driver is free again');
        $this->assertStringContainsString('closed as not delivered', $this->lastTo($rami->whatsapp_number));
    }

    public function test_a_driver_cannot_mark_an_order_failed_before_picking_it_up(): void
    {
        $rami = $this->driver();
        $order = $this->order();

        $this->tap("drv:failed:{$order->id}", $rami->whatsapp_number);

        $this->assertSame('confirmed', $order->fresh()->status);
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
        $this->assertTrue(collect($this->sentTo(self::CUSTOMER))->contains(fn ($m) => str_contains($m['text'], '12.00$ (1,077,000 LBP)')));
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
        $this->assertStringContainsString('Got your pin', $this->lastTo(self::CUSTOMER), 'the pin sent before checkout is not asked for again');
        foreach (['co:skip:', 'co:pay:cash', 'co:cur:USD', 'co:ok:'] as $step) {
            $this->tap($step, self::CUSTOMER);
        }
        $this->shopTypes(self::CUSTOMER, '✅');

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

        $this->assertStringContainsString('new time: preparing now, ready around', $this->lastTo(self::CUSTOMER));
        $apologies = array_filter($this->sentTo(self::CUSTOMER), fn ($m) => str_contains($m['text'], 'taking a bit longer'));
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
        $this->assertSame('Thanks for the rating! 🙏', $this->lastTo(self::CUSTOMER));
    }

    /** @return array{text: string, buttons: list<string>} */
    private function lastMessageTo(string $phone): array
    {
        $msgs = $this->sentTo($phone);

        return end($msgs) ?: ['text' => '', 'buttons' => []];
    }

    public function test_after_delivery_there_is_one_rating_question_with_three_taps(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $msg = $this->lastMessageTo(self::CUSTOMER);
        $this->assertStringContainsString('is with you', $msg['text']);
        $this->assertStringContainsString('How was your order?', $msg['text']);
        $this->assertSame(["rt:{$order->id}:order:5", "rt:{$order->id}:order:3", "rt:{$order->id}:order:1"], $msg['buttons']);

        $this->tap("rt:{$order->id}:order:5", self::CUSTOMER);
        $this->assertSame('Thanks for the rating! 🙏', $this->lastTo(self::CUSTOMER), 'Great is thanked and nothing more is asked');
        $this->assertSame(5, $order->fresh()->rating);
    }

    public function test_okay_or_bad_asks_what_went_wrong(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->tap("rt:{$order->id}:order:1", self::CUSTOMER);

        $this->assertStringContainsString('Tell us what went wrong', $this->lastTo(self::CUSTOMER));
        $this->assertSame(1, $order->fresh()->rating);
    }

    public function test_what_the_customer_writes_after_a_bad_rating_is_kept_and_the_bot_steps_back(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);
        $this->tap("rt:{$order->id}:order:3", self::CUSTOMER);

        $this->say('The fries were cold and it came late');

        $this->assertSame('The fries were cold and it came late', $order->fresh()->rating_issue);
        $this->assertStringContainsString('has your message and will reply here', $this->lastTo(self::CUSTOMER));
        $this->assertNotNull(Customer::query()->withoutGlobalScopes()->where('phone', self::CUSTOMER)->first()->bot_paused_until, 'the shop answers personally');

        $before = count($this->sentTo(self::CUSTOMER));
        $this->say('hello again'); // after the issue message the bot is paused: the shop answers
        $this->assertSame($before + 1, count($this->sentTo(self::CUSTOMER)), 'only the short "team is answering" note');
    }

    public function test_a_great_rating_asks_for_no_issue_and_a_later_message_is_an_ordinary_one(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);
        $this->tap("rt:{$order->id}:order:5", self::CUSTOMER);

        $this->say('menu');

        $this->assertNull($order->fresh()->rating_issue);
        $this->assertStringNotContainsString('has your message', $this->lastTo(self::CUSTOMER));
    }

    public function test_tapping_a_rating_twice_does_not_change_it(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->tap("rt:{$order->id}:order:5", self::CUSTOMER);
        $this->tap("rt:{$order->id}:order:1", self::CUSTOMER);

        $this->assertSame(5, $order->fresh()->rating, 'the first answer stands');
    }

    public function test_a_rating_button_from_another_number_is_ignored(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->tap("rt:{$order->id}:order:1", '+96170999888');

        $this->assertNull($order->fresh()->rating);
    }

    public function test_a_number_typed_in_a_new_order_after_the_rating_question_is_not_a_rating(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->travel(1)->minutes();
        $this->say('add 1 shawarma');
        $this->say('2'); // a number in the new order, not the answer to the rating question

        $this->assertNull($order->fresh()->rating);
        $this->assertStringNotContainsString('not perfect', $this->lastTo(self::CUSTOMER));
    }

    public function test_low_rating_in_arabic_digits_gets_an_apology(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->say('٢');

        $this->assertSame(2, $order->fresh()->rating);
        $this->assertStringContainsString("Sorry it wasn't perfect", $this->lastTo(self::CUSTOMER));
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
        $this->assertStringContainsString('someone from Beirut Bites', $this->lastTo(self::CUSTOMER));

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
        $this->assertStringContainsString("Cancel order #{$order->number}", $last['text']);
        $this->assertSame(["cx:yes:{$order->id}", "cx:no:{$order->id}"], $last['buttons'], 'the buttons decide, not the assistant');
        $this->assertSame($before, $order->fresh()->status, 'nothing is cancelled until the customer taps Yes');

        $this->tap("cx:no:{$order->id}", self::CUSTOMER);
        $this->assertSame($before, $order->fresh()->status, 'No keeps the order');
        $this->assertStringContainsString("Keeping order #{$order->number}", $this->lastTo(self::CUSTOMER));
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
        $this->assertStringContainsString('will reply here shortly', $this->lastTo(self::CUSTOMER));
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
        $this->assertTrue(collect($this->sentTo(self::CUSTOMER))->contains(fn ($m) => str_contains($m['text'], 'you pay 5.00$ + 627,000 LBP')));
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

    public function test_the_report_can_go_to_the_shops_own_number(): void
    {
        // The report is sent from NeoTalab's own number, so an owner with a single number gets it there.
        $settings = $this->merchant->settings;
        $settings['bot']['display_phone_number'] = '+961 71 444 555';
        $this->merchant->update(['settings' => $settings]);

        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['report_whatsapp' => '71 444 555'])
            ->assertOk()->assertJsonPath('profile.report_whatsapp', '+96171444555');
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
        $customer = array_keys(CustomerTemplates::definitions());
        $owner = array_values(array_diff(array_keys(ShopTemplateService::definitions()), $customer));
        $this->assertCount(3 + 42, $submitted, 'the billing reminder in three languages, fourteen customer events in three languages');
        $this->assertSame(array_map(fn ($n) => $n.':UTILITY', [...$owner, ...$customer]), $submitted);
        $this->assertContains(ShopTemplateService::BILLING_REMINDER, $owner);
        $arabic = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => $r->method() === 'POST' && ($r['name'] ?? null) === 'neotalab_c_order_ready_ar');
        $this->assertSame('ar', $arabic['language'], 'a customer template is submitted in its own language');
        $this->assertSame('PENDING', $this->merchant->fresh()->settings['bot']['templates'][ShopTemplateService::BILLING_REMINDER]['status']);

        app(ShopTemplateService::class)->sync($this->merchant->fresh());
        $this->assertFalse(app(ShopTemplateService::class)->isApproved($this->merchant->fresh(), 'neotalab_daily_report'), 'the report is no longer a shop template');
    }

    // ── pickup shops + staff numbers ────────────────────────────────────────

    private function shopSettings(array $values): void
    {
        $this->merchant->update(['settings' => array_merge($this->merchant->fresh()->settings, $values)]);
    }

    public function test_pickup_shop_takes_orders_without_address_or_driver(): void
    {
        $this->shopSettings(['fulfillment' => 'pickup', 'pickup_address' => 'Hamra St, next to the bakery']);
        $rami = $this->driver();

        $order = $this->order(confirm: 'confirm | | | cash | USD');

        $this->assertSame('pickup', $order->fulfillment);
        $this->assertNull($order->driver_id, 'pickup orders never go to a driver');
        $this->assertEquals(0.0, (float) $order->delivery_fee);
        $this->assertStringContainsString('ready for pickup around', $this->lastTo(self::CUSTOMER));
        $this->assertSame([], $this->sentTo($rami->whatsapp_number));
    }

    public function test_the_shop_closes_a_refund_by_typing_refunded_and_the_number(): void
    {
        $order = $this->order();
        $order->forceFill(['status' => 'cancelled', 'payment_status' => 'refund_due'])->save();

        $this->shopTypes(self::CUSTOMER, "refunded {$order->number}");

        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_a_pickup_order_is_accepted_made_ready_and_closed_by_typing(): void
    {
        $this->shopSettings(['fulfillment' => 'pickup', 'pickup_address' => 'Hamra St']);
        $this->say('add 2 shawarma');
        $this->say('confirm | | | cash | USD');
        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->sole();
        $this->assertSame('placed', $order->status);
        $this->assertStringContainsString('sent to', $this->lastTo(self::CUSTOMER));

        $this->shopTypes(self::CUSTOMER, '✅');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertStringContainsString('accepted order #1 — ready for pickup around', $this->lastTo(self::CUSTOMER));

        $this->shopTypes(self::CUSTOMER, 'ready');
        $this->assertSame('ready', $order->fresh()->status);
        $this->assertStringContainsString('ready for pickup at Hamra St', $this->lastTo(self::CUSTOMER));

        $this->shopTypes(self::CUSTOMER, 'done');
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertStringContainsString('Thanks for picking up #1', $this->lastTo(self::CUSTOMER));
    }

    public function test_a_shop_delivering_itself_closes_orders_by_typing(): void
    {
        $order = $this->order();
        $this->assertNull($order->fresh()->driver_id, 'no driver on the shift: the shop delivers itself');

        $this->shopTypes(self::CUSTOMER, 'out');
        $this->assertSame('🛵 Order #1 is on its way — arriving in about 15 min (around 12:15 PM).', $this->lastTo(self::CUSTOMER));

        $this->shopTypes(self::CUSTOMER, 'done');
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_a_word_typed_with_no_open_order_does_nothing(): void
    {
        $this->say('hello');
        $this->shopTypes(self::CUSTOMER, 'done');
        $this->shopTypes(self::CUSTOMER, '✅');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_shop_offering_both_asks_and_respects_the_choice(): void
    {
        $this->shopSettings(['fulfillment' => 'both']);

        $this->say('add 1 shawarma');
        $this->say('confirm');
        $asked = $this->sentTo(self::CUSTOMER);
        $ask = end($asked);
        $this->assertStringContainsString('Delivery or pickup?', $ask['text'], 'the buttons, not a plain question');
        $this->assertSame(['co:ful:delivery', 'co:ful:pickup'], $ask['buttons']);

        $this->say('confirm | | | cash | | | pickup');
        $this->tap('co:cur:USD', self::CUSTOMER); // the shop takes lira too: which money is asked next
        $this->tap('co:ok:', self::CUSTOMER);
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
        $this->assertStringContainsString('Delivery or pickup?', end($asked)['text']);
        $this->assertSame(['co:ful:delivery', 'co:ful:pickup'], end($asked)['buttons']);

        // A typed "delivery" moves on to the next question (the address), without the assistant.
        $this->say('delivery');
        $this->assertStringNotContainsString('understand', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('Share your location', $this->lastTo(self::CUSTOMER));
    }
}
