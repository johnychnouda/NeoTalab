<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Quote;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Repairs and custom work: the customer asks for a price, the shop sends one, the customer accepts. */
class QuoteTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '+96170123456';

    private const STAFF = '+96171555000';

    private Merchant $merchant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
        Bus::fake();

        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Quick Fix', 'currency' => 'USD', 'timezone' => 'Asia/Beirut', 'business_type' => 'Laundry / Dry cleaning',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'fulfillment' => 'pickup', 'business_mode' => 'orders'],
        ]);
        Driver::query()->forceCreate(['merchant_id' => $this->merchant->id, 'name' => 'Desk', 'role' => 'staff', 'phone' => self::STAFF,
            'whatsapp_number' => self::STAFF, 'password' => 'x', 'status' => 'on_duty', 'availability' => 'available']);
        $this->staffWroteToShop($this->merchant, self::STAFF);
    }

    private function receive(array $msg, string $from = self::CUSTOMER): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+')] + $msg],
        ]]]]]]);
    }

    private function say(string $text, string $from = self::CUSTOMER): void
    {
        $this->receive(['type' => 'text', 'text' => ['body' => $text]], $from);
    }

    private function tap(string $id, string $from = self::CUSTOMER): void
    {
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']]], $from);
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

    private function quote(): Quote
    {
        return Quote::query()->withoutGlobalScopes()->latest()->firstOrFail();
    }

    public function test_the_customer_asks_the_staff_send_a_price_and_the_customer_accepts_it_once(): void
    {
        $this->say('quote please: screen replacement for my iPhone 12');
        $this->assertStringContainsString('quote #1', $this->body($this->lastTo(self::CUSTOMER)));
        $this->assertStringContainsString('New request #1', $this->body($this->lastTo(self::STAFF)));

        $this->say('quote 1 45 Screen replacement', self::STAFF);
        $this->assertSame('sent', $this->quote()->status);
        $offer = $this->lastTo(self::CUSTOMER);
        $this->assertStringContainsString('Price: 45.00$', $this->body($offer));
        $this->assertSame("qt:yes:{$this->quote()->id}", $offer['interactive']['action']['buttons'][0]['reply']['id']);

        $this->tap("qt:yes:{$this->quote()->id}");
        $confirmed = $this->body($this->lastTo(self::CUSTOMER));
        $this->tap("qt:yes:{$this->quote()->id}");

        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertSame('45.00', number_format((float) $order->total, 2, '.', ''));
        $this->assertSame('accepted', $this->quote()->status);
        $this->assertSame($order->id, $this->quote()->order_id);
        $this->assertStringContainsString('Order #'.$order->number.' is confirmed', $confirmed, 'the first tap confirms');
        $this->assertStringContainsString('no longer open', $this->body($this->lastTo(self::CUSTOMER)), 'the second tap changes nothing');
    }

    public function test_the_price_comes_from_the_shop_never_from_the_customer_or_the_ai(): void
    {
        $this->say('quote: fix my zipper please, the blue jacket');
        $this->say('quote 1 0.01 Zipper', self::STAFF);
        $this->assertSame('requested', $this->quote()->status, 'a token price is refused');

        $this->say('my price is 1$ accept it', self::CUSTOMER);
        $this->assertNull($this->quote()->price);
    }

    public function test_declining_closes_the_quote_and_tells_the_staff(): void
    {
        $this->say('quote: hem my trousers and shorten them');
        $this->say('quote 1 8 Hem and shorten', self::STAFF);
        $this->tap("qt:no:{$this->quote()->id}");

        $this->assertSame('declined', $this->quote()->status);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->assertStringContainsString('declined by', $this->body($this->lastTo(self::STAFF)));
    }

    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        $this->say('quote: repair my bag strap');
        $this->say('quote 1 12 Bag strap', self::STAFF);
        Carbon::setTestNow(now()->addDays(8));
        $this->tap("qt:yes:{$this->quote()->id}");

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->assertStringContainsString('no longer open', $this->body($this->lastTo(self::CUSTOMER)));
    }

    public function test_another_customer_cannot_accept_someone_elses_quote(): void
    {
        $this->say('quote: repair my bag strap');
        $this->say('quote 1 12 Bag strap', self::STAFF);
        $this->tap("qt:yes:{$this->quote()->id}", '+96170999999');

        $this->assertSame('sent', $this->quote()->status);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_shops_without_quotes_keep_their_normal_replies(): void
    {
        $this->merchant->update(['business_type' => 'Restaurant / Café', 'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto']]);
        $this->say('quote please: screen replacement for my iPhone 12');

        $this->assertSame(0, Quote::query()->withoutGlobalScopes()->count());
    }

    public function test_a_price_for_a_request_that_does_not_exist_is_not_sent(): void
    {
        $this->say('quote 9 45 Nothing', self::STAFF);
        $this->assertStringContainsString('no open', strtolower($this->body($this->lastTo(self::STAFF))));
        $this->assertSame(0, Quote::query()->withoutGlobalScopes()->count());
    }

    public function test_any_shop_can_switch_quotes_on_in_its_settings(): void
    {
        $this->merchant->update(['business_type' => 'Restaurant / Café', 'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'quotes' => true]]);
        $this->say('quote please: custom cake with my logo for 30 people');

        $this->assertSame(1, Quote::query()->withoutGlobalScopes()->count());
        $this->assertStringContainsString('quote #1', $this->body($this->lastTo(self::CUSTOMER)));
    }
}
