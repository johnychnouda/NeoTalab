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
