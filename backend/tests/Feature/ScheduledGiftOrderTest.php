<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\RequestOrderRating;
use App\Jobs\StartScheduledOrder;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopHour;
use App\Services\OrderConfirmation;
use App\Services\WhatsApp\StaffMessenger;
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

/** Florists, cakes, gifts: the customer picks the delivery window and who receives it, with a card. */
class ScheduledGiftOrderTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '+96170123456';

    private const DRIVER = '+96171222333';

    private Merchant $merchant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Tuesday 6 October 2026, noon in Beirut.
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
        Bus::fake([StartScheduledOrder::class, CheckLateOrder::class, CheckUnacceptedOrder::class, RequestOrderRating::class]);

        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Rose Garden', 'currency' => 'USD', 'timezone' => 'Asia/Beirut', 'business_type' => 'Flowers / Gifts',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'fulfillment' => 'delivery', 'scheduled_orders' => true, 'gift_orders' => true],
        ]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Roses', 'price' => 30]);
        DeliveryZone::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Hamra', 'delivery_fee' => 3, 'minimum_order' => 0, 'is_active' => true]);
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Sami', 'role' => 'driver', 'phone' => self::DRIVER,
            'whatsapp_number' => self::DRIVER, 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, self::DRIVER);
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
    private function lastTo(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)->last()?->data() ?? [];
    }

    private function body(array $m): string
    {
        return $m['text']['body'] ?? ($m['interactive']['body']['text'] ?? '');
    }

    /** @return list<string> */
    private function ids(array $m): array
    {
        $i = $m['interactive'] ?? [];

        return array_merge(
            array_map(fn ($b) => $b['reply']['id'], $i['action']['buttons'] ?? []),
            array_map(fn ($r) => $r['id'], $i['action']['sections'][0]['rows'] ?? []),
        );
    }

    private function toReceipt(): void
    {
        $this->say('add 1 Roses');
        $this->say('done');
        $this->say('Bliss St, Building 4');
    }

    public function test_the_customer_picks_friday_ten_to_twelve_and_sends_it_to_someone(): void
    {
        $this->toReceipt();
        $this->assertSame(['co:when:asap', 'co:when:pick'], $this->ids($this->lastTo(self::CUSTOMER)));

        $this->tap('co:when:pick');
        $this->assertContains('co:day:2026-10-09', $this->ids($this->lastTo(self::CUSTOMER)));
        $this->tap('co:day:2026-10-09');
        $this->assertContains('co:win:2026-10-09 11:00', $this->ids($this->lastTo(self::CUSTOMER)));
        $this->tap('co:win:2026-10-09 11:00');

        $this->assertSame(['co:gift:yes', 'co:gift:no'], $this->ids($this->lastTo(self::CUSTOMER)));
        $this->tap('co:gift:yes');
        $this->say('Lara');
        $this->assertStringContainsString('name and phone number together', $this->body($this->lastTo(self::CUSTOMER)));
        $this->say('Lara 70 123 456');
        $this->say('Happy birthday!');

        $receipt = $this->body($this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('🗓 Fri 9 Oct, 11:00–13:00', $receipt);
        $this->assertStringContainsString('🎁 Lara · 70123456', $receipt);
        $this->assertStringContainsString('Happy birthday!', $receipt);
        $this->tap('co:ok:');

        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertSame('2026-10-09 11:00', $order->deliver_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-09 13:00', $order->promised_at->setTimezone('Asia/Beirut')->format('Y-m-d H:i'));
        $this->assertSame(['Lara', '70123456', 'Happy birthday!'], [$order->recipient_name, $order->recipient_phone, $order->gift_message]);
        $this->assertStringContainsString('delivered on Fri 9 Oct, 11:00–13:00', $this->body($this->lastTo(self::CUSTOMER)));
        $this->assertNull($order->driver_id, 'no driver three days early');
        Bus::assertDispatched(StartScheduledOrder::class);

        // Friday, when it is time to prepare it: a driver, who calls Lara.
        Carbon::setTestNow(Carbon::parse('2026-10-09 10:30', 'Asia/Beirut'));
        (new StartScheduledOrder($order->id))->handle(app(OrderConfirmation::class), app(StaffMessenger::class));
        $this->assertNotNull($order->fresh()->driver_id);
        $card = Http::recorded()->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === '96171222333')
            ->map(fn ($r) => json_encode($r->data(), JSON_UNESCAPED_UNICODE))->implode(' ');
        $this->assertStringContainsString('70123456', $card);
        $this->assertStringContainsString('Lara', $card);
    }

    public function test_as_soon_as_possible_and_not_a_gift_works_like_before(): void
    {
        $this->toReceipt();
        $this->tap('co:when:asap');
        $this->tap('co:gift:no');
        $this->assertStringContainsString('⏱', $this->body($this->lastTo(self::CUSTOMER)));
        $this->tap('co:ok:');

        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertNull($order->deliver_at);
        $this->assertNull($order->recipient_name);
        $this->assertNotNull($order->driver_id);
    }

    public function test_a_closed_shop_still_takes_an_order_for_an_open_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 23:30', 'Asia/Beirut'));
        $this->toReceipt();

        $days = $this->ids($this->lastTo(self::CUSTOMER));
        $this->assertStringContainsString('closed right now', $this->body($this->lastTo(self::CUSTOMER)));
        $this->assertNotContains('co:when:asap', $days);
        $this->assertContains('co:day:2026-10-07', $days);
    }

    public function test_only_real_windows_are_accepted(): void
    {
        ShopHour::query()->forceCreate(['merchant_id' => $this->merchant->id, 'day_of_week' => Carbon::FRIDAY, 'opens_at' => '09:00', 'closes_at' => '13:00', 'is_closed' => false]);
        app(CurrentTenant::class)->set($this->merchant);

        $this->assertNotNull(DeliverySchedule::parse($this->merchant, '2026-10-09 11:00'));
        $this->assertNull(DeliverySchedule::parse($this->merchant, '2026-10-09 12:00'), 'would end after closing');
        $this->assertNull(DeliverySchedule::parse($this->merchant, '2026-10-09 10:30'), 'not a window start');
        $this->assertNull(DeliverySchedule::parse($this->merchant, '2026-10-06 12:00'), 'no time to prepare it');
        $this->assertNull(DeliverySchedule::parse($this->merchant, 'friday'));
    }

    public function test_shops_that_did_not_switch_it_on_see_no_new_questions(): void
    {
        $this->merchant->update(['settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'fulfillment' => 'delivery']]);
        $this->toReceipt();

        $this->assertContains('co:ok:', $this->ids($this->lastTo(self::CUSTOMER)), 'straight to the receipt');
    }
}
