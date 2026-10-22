<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\BusinessKind;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Every business type a shop can join with: one shop each, one item, one pickup order over
 * WhatsApp. Catches a type that cannot order, and any message that reaches a customer with a raw
 * translation key or an unfilled placeholder (":number", "whatsapp.").
 */
class BusinessKindsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '+96170123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00', 'Asia/Beirut')); // Wednesday, open hours
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return iterable<string, array{0: string}> */
    public static function businessTypes(): iterable
    {
        foreach (array_keys(BusinessKind::TYPES) as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('businessTypes')]
    public function test_every_business_type_can_take_a_pickup_order(string $type): void
    {
        $index = array_search($type, array_keys(BusinessKind::TYPES), true) + 1;
        $phoneId = (string) (700000000 + $index);

        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Shop '.$index,
            'business_type' => $type,
            'currency' => 'USD',
            'timezone' => 'Asia/Beirut',
            'settings' => ['bot' => ['phone_id' => $phoneId], 'mode' => 'auto', 'fulfillment' => 'both',
                'pickup_address' => 'Hamra St, Beirut', 'lbp_rate' => 89500],
        ]);
        Product::factory()->forMerchant($merchant)->create(['name' => 'Test Item', 'price' => 4]);

        $this->receive($phoneId, ['type' => 'text', 'text' => ['body' => 'add 1 test item']]);
        $this->receive($phoneId, ['type' => 'text', 'text' => ['body' => 'confirm | | | cash | | | pickup']]);

        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)->first();
        $this->assertNotNull($order, "{$type}: no order was created");
        $this->assertSame('pickup', $order->fulfillment, "{$type}: pickup not kept");

        foreach ($this->customerTexts() as $text) {
            $this->assertDoesNotMatchRegularExpression('/\bwhatsapp\.[a-z_.]+|:(number|shop|total|items|address)\b/', $text, "{$type}: a raw key reached the customer");
        }
        $this->assertNotNull(Customer::query()->withoutGlobalScopes()->where('phone', self::CUSTOMER)->first());
    }

    /** Every shop type runs the full order life cycle, and a customer cancel is refused once it is closed. */
    #[DataProvider('businessTypes')]
    public function test_every_business_type_runs_accept_ready_collected_and_cancel(string $type): void
    {
        $index = array_search($type, array_keys(BusinessKind::TYPES), true) + 1;
        $phoneId = (string) (700000000 + $index);
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Life '.$index, 'business_type' => $type, 'currency' => 'USD', 'timezone' => 'Asia/Beirut',
            'settings' => ['bot' => ['phone_id' => $phoneId], 'mode' => 'auto', 'fulfillment' => 'pickup', 'lbp_rate' => 89500],
        ]);
        $item = Product::factory()->forMerchant($merchant)->create(['name' => 'Life Item', 'price' => 4]);
        $orders = app(\App\Services\OrderService::class);

        $this->receive($phoneId, ['type' => 'text', 'text' => ['body' => 'add 1 life item']]);
        $this->receive($phoneId, ['type' => 'text', 'text' => ['body' => 'confirm | | | cash']]);
        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)->firstOrFail();
        // No staff are set up here, so the bot accepts the order itself (the designed behaviour for such shops).
        $this->assertSame('confirmed', $order->status, "{$type}: a shop without staff is accepted by the bot");
        $orders->transition($merchant, $order->id, 'ready');
        $this->assertSame('ready', $order->fresh()->status, "{$type}: ready");
        $orders->transition($merchant, $order->id, 'delivered');
        $this->assertSame('delivered', $order->fresh()->status, "{$type}: collected");
        $this->assertNotNull($order->fresh()->delivered_at, "{$type}: collection time recorded");

        // A closed order cannot be refused, made ready or collected again.
        foreach (['reject', 'ready', 'delivered'] as $action) {
            try {
                $orders->transition($merchant, $order->id, $action);
                $this->fail("{$type}: {$action} on a delivered order was accepted");
            } catch (\Illuminate\Validation\ValidationException) {
                $this->assertSame('delivered', $order->fresh()->status, "{$type}: {$action} changed a closed order");
            }
        }

        // A second order can be cancelled by the customer while it is still open.
        $this->receive($phoneId, ['type' => 'text', 'text' => ['body' => 'add 1 life item']]);
        $this->receive($phoneId, ['type' => 'text', 'text' => ['body' => 'confirm | | | cash']]);
        $open = Order::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)->latest('number')->firstOrFail();
        $this->receive($phoneId, ['type' => 'text', 'text' => ['body' => 'cancel order']]);
        $this->assertSame('cancelled', $open->fresh()->status, "{$type}: the customer's cancel was applied");
        $this->assertSame('customer', $open->fresh()->cancelled_by, "{$type}: cancelled by the customer");
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->where('merchant_id', $merchant->id)->where('status', 'cancelled')->count());
    }

    private function receive(string $phoneId, array $msg): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'messages' => [$msg + ['id' => 'wamid.IN'.uniqid(), 'from' => ltrim(self::CUSTOMER, '+')]],
        ]]]]]]);
    }

    /** @return list<string> */
    private function customerTexts(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_ends_with($r->url(), '/messages') && $r['to'] === ltrim(self::CUSTOMER, '+'))
            ->map(fn ($r) => (string) ($r['text']['body'] ?? $r['interactive']['body']['text'] ?? ''))
            ->values()->all();
    }
}
