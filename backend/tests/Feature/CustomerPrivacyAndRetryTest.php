<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Jobs\NotifyShopOfOrder;
use App\Models\AiMemory;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\ShopPhone;
use Tests\TestCase;

/**
 * Two things the shop does after the fact: erase a customer's personal details on request, and
 * send a delivery that failed out again with another driver.
 */
class CustomerPrivacyAndRetryTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, ShopPhone;

    private const CUSTOMER = '+96170123456';

    private Merchant $merchant;

    private string $token;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class, NotifyShopOfOrder::class]);

        [$this->merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Beirut Bites',
            'currency' => 'USD',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'prep_time_mins' => 25],
        ]);
        $this->token = $this->tokenFor($owner);

        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shawarma', 'price' => 6]);
    }

    private function receive(array $msg, string $from = self::CUSTOMER): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [$msg + ['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+')]],
        ]]]]]]);
    }

    private function say(string $text): void
    {
        $this->receive(['type' => 'text', 'text' => ['body' => $text]]);
    }

    private function driverTaps(string $buttonId, Driver $driver): void
    {
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $buttonId, 'title' => 'x']]], $driver->whatsapp_number);
    }

    private function order(): Order
    {
        $this->say('add 2 shawarma');
        $this->say('confirm | | Bliss St, Building 4 | cash');
        $this->shopTypes(self::CUSTOMER, '✅');

        return Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->latest('number')->firstOrFail();
    }

    private function driver(string $name, string $wa): Driver
    {
        return Driver::query()->forceCreate([
            'merchant_id' => $this->merchant->id, 'name' => $name, 'role' => 'driver', 'phone' => $wa, 'whatsapp_number' => $wa,
            'password' => 'secret123', 'status' => 'on_duty', 'availability' => 'available', 'shift_started_at' => now(),
        ]);
    }

    private function lastToCustomer(): string
    {
        $digits = preg_replace('/\D+/', '', self::CUSTOMER);
        $last = Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)->last();

        return (string) ($last['text']['body'] ?? ($last['interactive']['body']['text'] ?? ''));
    }

    private function failedOrder(): Order
    {
        $rami = $this->driver('Rami', '+96176000001');
        $order = $this->order();
        $this->driverTaps("drv:picked_up:{$order->id}", $rami);
        $this->driverTaps("drv:failed:{$order->id}", $rami);
        $this->assertSame('failed', $order->fresh()->status);

        return $order->fresh();
    }

    // ── anonymize ────────────────────────────────────────────────────────────

    public function test_anonymizing_a_customer_erases_their_details_and_keeps_the_orders(): void
    {
        $order = $this->order();
        $customer = Customer::query()->withoutGlobalScopes()->findOrFail($order->customer_id);
        $customer->forceFill(['name' => 'Maya', 'addresses' => [['label' => 'Home', 'address' => 'Bliss St, Building 4']]])->save();
        AiMemory::query()->withoutGlobalScopes()->forceCreate([
            'merchant_id' => $this->merchant->id, 'customer_id' => $customer->id, 'scope' => 'customer', 'source' => 'owner', 'content' => 'Maya likes extra garlic',
        ]);

        $this->withToken($this->token)->postJson("/api/v1/customers/{$customer->id}/anonymize")->assertOk();

        $customer->refresh();
        $this->assertSame('Deleted customer', $customer->name);
        $this->assertStringStartsWith('+deleted-', $customer->phone);
        $this->assertNull($customer->addresses);
        $this->assertTrue($customer->is_blocked);
        $this->assertSame(0, AiMemory::query()->withoutGlobalScopes()->where('customer_id', $customer->id)->count());

        $order->refresh();
        $this->assertSame($customer->id, $order->customer_id, 'the order stays in the shop records');
        $this->assertNull($order->delivery_address);
    }

    public function test_one_shop_cannot_anonymize_another_shops_customer(): void
    {
        $order = $this->order();
        [, $other] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Other Shop']);

        $this->withToken($this->tokenFor($other))->postJson("/api/v1/customers/{$order->customer_id}/anonymize")->assertNotFound();

        $this->assertSame(self::CUSTOMER, Customer::query()->withoutGlobalScopes()->findOrFail($order->customer_id)->phone);
    }

    // ── retry a failed delivery ──────────────────────────────────────────────

    public function test_retry_sends_a_failed_delivery_out_again_with_a_free_driver(): void
    {
        $order = $this->failedOrder();

        $this->shopTypes(self::CUSTOMER, 'retry');

        $order->refresh();
        $this->assertContains($order->status, ['ready', 'assigned']);
        $this->assertNull($order->cancel_reason);
        $this->assertNull($order->cancelled_at);
    }

    public function test_retrying_a_paid_whish_order_no_longer_says_a_refund_is_owed(): void
    {
        $order = $this->failedOrder();
        // What a failed delivery leaves on a Whish order the customer already paid.
        $order->forceFill(['payment_method' => 'whish', 'payment_status' => 'refund_due'])->save();

        $this->shopTypes(self::CUSTOMER, 'retry #'.$order->number);

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_retry_with_nothing_to_retry_says_so(): void
    {
        $this->order();

        $this->shopTypes(self::CUSTOMER, 'retry');

        $this->assertSame('No failed order to retry.', $this->lastToCustomer());
    }
}
