<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\BillingReminderService;
use App\Services\DailyReportService;
use App\Services\TrialExpiryService;
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

    public function test_low_rating_in_arabic_digits_gets_an_apology(): void
    {
        $rami = $this->driver();
        $order = $this->order();
        $this->tap("drv:delivered:{$order->id}", $rami->whatsapp_number);

        $this->say('٢');

        $this->assertSame(2, $order->fresh()->rating);
        $this->assertStringContainsString('sorry it was not perfect', $this->lastTo(self::CUSTOMER));
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
        $this->assertCount($before, $this->sentTo(self::CUSTOMER), 'paused: the merchant answers');

        $this->echo(self::CUSTOMER, '▶️');
        $this->say('ok thanks, add 1 shawarma');
        $this->assertCount($before + 1, $this->sentTo(self::CUSTOMER), 'resumed immediately');
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

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('customer', $order->fresh()->cancelled_by);
        $this->assertStringContainsString('was cancelled', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('cancelled by the customer', $this->lastTo($rami->whatsapp_number));
        $this->assertNull($rami->fresh()->active_order_id);
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
        $this->assertCount($before, $this->sentTo(self::CUSTOMER));

        $this->echo(self::CUSTOMER, '▶️');
        $this->say('menu');
        $this->assertCount($before + 1, $this->sentTo(self::CUSTOMER));
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
            ShopTemplateService::DAILY_REPORT => ['id' => '1', 'status' => 'APPROVED'],
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

    public function test_owner_gets_the_nightly_report_from_the_shop_number_once(): void
    {
        $this->ownerNumber();
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
        $this->assertStringContainsString('/555/messages', $sent[0]->url(), 'sent from the shop number');
        $this->assertStringContainsString('Orders delivered: 1', $sent[0]['text']['body']);
        $this->assertStringContainsString('Sales: 12.00$', $sent[0]['text']['body']);
        $this->assertStringContainsString('Shawarma ×2', $sent[0]['text']['body']);
    }

    public function test_report_uses_the_shops_approved_template(): void
    {
        $this->ownerNumber();
        $this->approveTemplates();

        app(DailyReportService::class)->sendDue(Carbon::parse('2026-10-06 23:05', 'Asia/Beirut'));

        $sent = $this->requestsTo('+96171222333')[0];
        $this->assertSame('template', $sent['type']);
        $this->assertSame(ShopTemplateService::DAILY_REPORT, $sent['template']['name']);
        $this->assertCount(7, $sent['template']['components'][0]['parameters']);
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
            'meta.test/*/waba-1/message_templates?*' => Http::response(['data' => [['name' => ShopTemplateService::DAILY_REPORT, 'status' => 'APPROVED']]], 200),
            'meta.test/*/waba-1/message_templates' => Http::response(['id' => 'tpl-1', 'status' => 'PENDING'], 200),
        ]);

        app(ShopTemplateService::class)->sync($this->merchant->fresh());

        $submitted = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/waba-1/message_templates'))
            ->map(fn (Request $r) => $r['name'].':'.$r['category'])->values()->all();
        $this->assertSame([
            ShopTemplateService::DAILY_REPORT.':UTILITY', ShopTemplateService::BILLING_REMINDER.':UTILITY',
            ShopTemplateService::NEW_ORDER.':UTILITY', ShopTemplateService::STAFF_ALERT.':UTILITY',
            ShopTemplateService::NEW_BOOKING.':UTILITY', ShopTemplateService::ORDER_DUE.':UTILITY', ShopTemplateService::REFUND_NEEDED.':UTILITY',
        ], $submitted);
        $newOrder = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => $r->method() === 'POST' && ($r['name'] ?? null) === ShopTemplateService::NEW_ORDER);
        $this->assertSame(['Accept', "Can't take it"], array_column($newOrder['components'][1]['buttons'], 'text'));
        $this->assertSame('PENDING', $this->merchant->fresh()->settings['bot']['templates'][ShopTemplateService::DAILY_REPORT]['status']);

        app(ShopTemplateService::class)->sync($this->merchant->fresh());
        $this->assertTrue(app(ShopTemplateService::class)->isApproved($this->merchant->fresh(), ShopTemplateService::DAILY_REPORT));
    }

    // ── pickup shops + staff numbers ────────────────────────────────────────

    /** A staff member who wrote to the shop number today (inside WhatsApp's 24-hour window). */
    private function staff(): Driver
    {
        $staff = $this->driver('Owner', '+96171555000', 'on_duty', 'staff');
        $this->staffWroteToShop($this->merchant, '+96171555000');

        return $staff;
    }

    private function shopSettings(array $values): void
    {
        $this->merchant->update(['settings' => array_merge($this->merchant->fresh()->settings, $values)]);
    }

    public function test_pickup_shop_takes_orders_without_address_or_driver(): void
    {
        $this->shopSettings(['fulfillment' => 'pickup', 'pickup_address' => 'Hamra St, next to the pharmacy']);
        $rami = $this->driver();

        $order = $this->order(confirm: 'confirm');

        $this->assertSame('pickup', $order->fulfillment);
        $this->assertNull($order->driver_id, 'pickup orders never go to a driver');
        $this->assertEquals(0.0, (float) $order->delivery_fee);
        $this->assertStringContainsString('ready for pickup around', $this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('at Hamra St, next to the pharmacy', $this->lastTo(self::CUSTOMER));
        $this->assertSame([], $this->sentTo($rami->whatsapp_number));
    }

    public function test_shop_offering_both_asks_and_respects_the_choice(): void
    {
        $this->shopSettings(['fulfillment' => 'both']);

        $this->say('add 1 shawarma');
        $this->say('confirm');
        $this->assertStringContainsString('delivery or will you pick up', $this->lastTo(self::CUSTOMER));

        $this->say('confirm | | | cash | | | pickup');
        $this->assertSame('pickup', Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->sole()->fulfillment);
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
