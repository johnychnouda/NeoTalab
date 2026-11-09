<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\StartScheduledOrder;
use App\Models\Conversation;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopHour;
use App\Services\OrderConfirmation;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\CurrentTenant;
use App\Support\DeliverySchedule;
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
 * The customer gets the shop's own time by default (no question). "Another time" on the order summary, or a time
 * written in plain words, picks a different slot: one hour for minutes shops, two for hours shops, a day for
 * shops that count in days; never earlier than the shop's own preparation time allows.
 */
class ChooseTimeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, \Tests\Concerns\ShopPhone;

    private const CUSTOMER = '+96170123456';

    private const DRIVER = '+96171222333';

    private Merchant $merchant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Tuesday 6 October 2026, noon in Beirut. Shops are open 09:00–23:00 unless a test says otherwise.
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
        Bus::fake([StartScheduledOrder::class, CheckLateOrder::class, CheckUnacceptedOrder::class, \App\Jobs\NotifyShopOfOrder::class]);

        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Rose Garden', 'currency' => 'USD', 'timezone' => 'Asia/Beirut', 'business_type' => 'Flowers / Gifts',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'fulfillment' => 'delivery'],
        ]);
        $this->useShop('hours');
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Roses', 'price' => 30]);
        DeliveryZone::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Hamra', 'delivery_fee' => 3, 'minimum_order' => 0, 'is_active' => true]);
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Sami', 'role' => 'driver', 'phone' => self::DRIVER,
            'whatsapp_number' => self::DRIVER, 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
    }

    /** The shop counts its preparation in minutes (30), hours (2) or days (2 to 3). */
    private function useShop(string $unit): void
    {
        $settings = ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'fulfillment' => 'delivery', 'timing_unit' => $unit];
        match ($unit) {
            'minutes' => $settings['prep_time_mins'] = 30,
            'hours' => $settings['prep_hours'] = 2,
            default => $settings += ['delivery_days_min' => 2, 'delivery_days_max' => 3],
        };
        $this->merchant->update(['settings' => $settings]);
    }

    private function receive(array $msg): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim(self::CUSTOMER, '+')] + $msg],
        ]]]]]]);
    }

    private function say(string $text): void
    {
        $this->receive(['type' => 'text', 'text' => ['body' => $text]]);
    }

    private function tap(string $id): void
    {
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']]]);
    }

    /** @return array<string, mixed> */
    private function last(): array
    {
        $digits = preg_replace('/\D+/', '', self::CUSTOMER);

        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)->last()?->data() ?? [];
    }

    private function body(): string
    {
        $m = $this->last();

        return $m['text']['body'] ?? ($m['interactive']['body']['text'] ?? '');
    }

    /** @return list<string> */
    private function ids(): array
    {
        $i = $this->last()['interactive'] ?? [];

        return array_merge(
            array_map(fn ($b) => $b['reply']['id'], $i['action']['buttons'] ?? []),
            array_map(fn ($r) => $r['id'], $i['action']['sections'][0]['rows'] ?? []),
        );
    }

    private function toSummary(): void
    {
        $this->say('add 1 Roses');
        $this->say('done');
        $this->receive(['type' => 'location', 'location' => ['latitude' => 33.8938, 'longitude' => 35.5018]]);
        $this->tap('co:skip:');
    }

    public function test_the_summary_gives_the_shops_own_time_and_offers_another_time_without_a_question(): void
    {
        $this->toSummary();

        $this->assertSame(['co:ok:', 'co:time:', 'co:cancel:'], $this->ids());
        $this->assertStringContainsString('⏱', $this->body());
        $this->assertStringContainsString('just write what you want', $this->body());

        $this->tap('co:ok:');
        $this->shopTypes(self::CUSTOMER, '✅');
        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertNull($order->deliver_at);
        $this->assertNotNull($order->driver_id);
    }

    public function test_another_time_is_one_list_and_the_pick_shows_on_the_summary(): void
    {
        $this->toSummary();
        $this->tap('co:time:');

        // Hours shop, 2 h of preparation, noon now: the first slot is 15:00 (14:00 is the earliest, windows start on odd hours).
        $ids = $this->ids();
        $this->assertSame('co:win:2026-10-06 15:00', $ids[0]);
        $this->assertContains('co:win:2026-10-07 11:00', $ids);
        $this->assertSame('co:when:pick', end($ids), '"Another day" is last');

        $this->tap('co:win:2026-10-07 11:00');
        $this->assertStringContainsString('🗓 Wed 7 Oct, 11:00–13:00', $this->body());
        $this->assertSame(['co:ok:', 'co:when:asap', 'co:cancel:'], $this->ids());

        $this->tap('co:ok:');
        $this->shopTypes(self::CUSTOMER, '✅');
        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertSame('2026-10-07 11:00', $order->deliver_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-07 13:00', $order->promised_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
        $this->assertStringContainsString('delivered on Wed 7 Oct, 11:00–13:00', $this->body());
        $this->assertNull($order->driver_id, 'no driver a day early');
        Bus::assertDispatched(StartScheduledOrder::class);

        // Wednesday, when it is time to prepare it: a driver.
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:45', 'Asia/Beirut'));
        (new StartScheduledOrder($order->id))->handle(app(OrderConfirmation::class));
        $this->assertNotNull($order->fresh()->driver_id);
    }

    public function test_another_day_leads_to_that_days_times(): void
    {
        $this->toSummary();
        $this->tap('co:time:');
        $this->tap('co:when:pick');
        $this->assertContains('co:day:2026-10-09', $this->ids());
        $this->tap('co:day:2026-10-09');
        $this->assertContains('co:win:2026-10-09 11:00', $this->ids());
        $this->tap('co:win:2026-10-09 11:00');
        $this->assertStringContainsString('🗓 Fri 9 Oct, 11:00–13:00', $this->body());
    }

    public function test_soonest_possible_goes_back_to_the_shops_own_time(): void
    {
        $this->toSummary();
        $this->tap('co:time:');
        $this->tap('co:win:2026-10-07 11:00');
        $this->tap('co:when:asap');

        $this->assertStringContainsString('⏱', $this->body());
        $this->assertSame(['co:ok:', 'co:time:', 'co:cancel:'], $this->ids());
        $this->tap('co:ok:');
        $this->shopTypes(self::CUSTOMER, '✅');
        $this->assertNull(Order::query()->withoutGlobalScopes()->sole()->deliver_at);
    }

    public function test_the_customer_can_write_the_time_and_a_minutes_shop_gives_one_hour_slots(): void
    {
        $this->useShop('minutes');
        $this->toSummary();

        $this->say('Can I get it tomorrow at 5?');
        $this->assertStringContainsString('🗓 Wed 7 Oct, 17:00–18:00', $this->body(), '5 is the evening: nothing opens at 05:00');
        $this->assertSame(['co:ok:', 'co:when:asap', 'co:cancel:'], $this->ids());

        $this->say('tomorrow at 10:30 am');
        $this->assertStringContainsString('🗓 Wed 7 Oct, 10:00–11:00', $this->body());

        $this->say('demain à 20h');
        $this->assertStringContainsString('🗓 Wed 7 Oct, 20:00–21:00', $this->body());

        $this->say('بكرا الساعة 9 مساء');
        $this->assertStringContainsString('🗓 Wed 7 Oct, 21:00–22:00', $this->body());

        $this->tap('co:ok:');
        $this->shopTypes(self::CUSTOMER, '✅');
        $this->assertSame('2026-10-07 21:00', Order::query()->withoutGlobalScopes()->sole()->deliver_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
    }

    public function test_a_written_time_the_shop_cannot_do_gets_the_earliest_times(): void
    {
        $this->useShop('minutes');
        $this->toSummary();

        $this->say('today at 12:10');
        $this->assertStringContainsString('The earliest is Tue 6 Oct, 13:00–14:00', $this->body());
        $this->assertContains('co:win:2026-10-06 13:00', $this->ids());
        $this->assertNotContains('co:win:2026-10-06 12:00', $this->ids(), 'it would not be ready in time');

        $this->say('tomorrow at 3 am');
        $this->assertStringContainsString('The earliest is', $this->body());
    }

    public function test_a_written_day_shows_that_days_times(): void
    {
        $this->useShop('minutes');
        $this->toSummary();

        $this->say('tomorrow');
        $this->assertContains('co:win:2026-10-07 09:00', $this->ids());
        $this->assertNotContains('co:win:2026-10-07 22:00', $this->ids(), 'nine to a page');
        $this->assertCount(10, $this->ids(), 'a WhatsApp list holds ten rows');

        $this->tap('co:day:2026-10-07:1');
        $this->assertContains('co:win:2026-10-07 22:00', $this->ids(), 'the evening is on the next page');
    }

    public function test_a_message_that_is_not_a_time_still_goes_to_the_assistant(): void
    {
        $this->toSummary();
        $this->say('add 1 Roses');

        $this->assertNull(Conversation::query()->withoutGlobalScopes()->sole()->state['checkout'] ?? null, 'the flow was left');
        $this->assertStringNotContainsString('🗓', $this->body());
    }

    public function test_a_shop_that_counts_in_days_offers_days_never_earlier_than_its_own_days(): void
    {
        $this->useShop('days');
        $this->toSummary();
        $this->tap('co:time:');

        // 2 working days from Tuesday: Thursday is the first.
        $ids = $this->ids();
        $this->assertSame('co:win:2026-10-08 09:00', $ids[0]);
        $this->assertNotContains('co:win:2026-10-07 09:00', $ids);
        $this->assertCount(7, $ids);

        $this->tap('co:win:2026-10-09 09:00');
        $this->assertStringContainsString('🗓 Fri 9 Oct', $this->body());
        $this->assertStringNotContainsString('09:00', $this->body(), 'a day, not a time');

        $this->say('friday');
        $this->assertStringContainsString('🗓 Fri 9 Oct', $this->body());
        $this->say('tomorrow');
        $this->assertStringContainsString('The earliest is Thu 8 Oct', $this->body(), 'Wednesday is too soon');

        $this->say('Saturday');
        $this->tap('co:ok:');
        $this->shopTypes(self::CUSTOMER, '✅');
        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertSame('2026-10-10', $order->deliver_at->setTimezone('Asia/Beirut')->format('Y-m-d'));
        $this->assertSame('2026-10-10 23:59', $order->promised_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'), 'promised by the end of that day');
        Bus::assertNotDispatched(StartScheduledOrder::class);
    }

    public function test_a_closed_shop_still_takes_an_order_for_an_open_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 23:30', 'Asia/Beirut'));
        $this->toSummary();

        $this->assertStringContainsString('closed right now', $this->body());
        $this->assertNotContains('co:when:asap', $this->ids());
        $this->assertContains('co:win:2026-10-07 09:00', $this->ids());

        $this->tap('co:win:2026-10-07 09:00');
        $this->assertSame(['co:ok:', 'co:time:', 'co:cancel:'], $this->ids(), 'no "soonest" while closed');
    }

    public function test_only_real_windows_are_accepted(): void
    {
        ShopHour::query()->forceCreate(['merchant_id' => $this->merchant->id, 'day_of_week' => Carbon::FRIDAY, 'opens_at' => '09:00', 'closes_at' => '13:00', 'is_closed' => false]);
        app(CurrentTenant::class)->set($this->merchant);

        // Hours shop: two-hour windows from opening time.
        $this->assertNotNull(DeliverySchedule::parse($this->merchant, '2026-10-09 11:00'));
        $this->assertNull(DeliverySchedule::parse($this->merchant, '2026-10-09 12:00'), 'not a window start');
        $this->assertNull(DeliverySchedule::parse($this->merchant, '2026-10-06 12:00'), 'no time to prepare it');
        $this->assertNull(DeliverySchedule::parse($this->merchant, 'friday'));

        $this->useShop('minutes');
        $this->merchant->refresh();
        $this->assertNotNull(DeliverySchedule::parse($this->merchant, '2026-10-09 12:00'), 'one-hour windows');
        $this->assertNull(DeliverySchedule::parse($this->merchant, '2026-10-09 13:00'), 'would end after closing');
        $this->assertNull(DeliverySchedule::parse($this->merchant, '2026-10-09 10:30'), 'not a window start');
    }

    public function test_the_old_edit_button_on_an_earlier_summary_still_opens_the_menu(): void
    {
        $this->toSummary();
        $this->tap('co:edit:');

        $this->assertStringContainsString('tell me what to change', $this->body());
    }
}
