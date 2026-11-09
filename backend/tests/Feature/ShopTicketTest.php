<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Models\Conversation;
use App\Models\DeliveryZone;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\DTO\ConversationTurnInput;
use App\Services\AI\DTO\StructuredTurnOutput;
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

class ShopTicketTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '+96170123456';

    private const OWNER = '+96171555999';

    private Merchant $merchant;

    private Product $shawarma;

    private int $seq = 0;

    /** @var list<array<string, mixed>> */
    public array $aiScript = [];

    public function nextAi(): array
    {
        return array_shift($this->aiScript) ?? ['recommended_action' => 'none', 'assistant_message' => 'ok'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:00', 'Asia/Beirut'));
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake([
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200),
            'routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '1200s']]], 200),
        ]);
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class]);

        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Beirut Bites', 'currency' => 'USD', 'timezone' => 'Asia/Beirut',
            'settings' => [
                'bot' => ['phone_id' => '555'],
                'mode' => 'auto',
                'prep_time_mins' => 25,
                'report_whatsapp' => self::OWNER,
                'fulfillment' => 'both',
            ],
        ]);
        $this->shawarma = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shawarma', 'price' => 6]);
        DeliveryZone::query()->forceCreate([
            'merchant_id' => $this->merchant->id, 'name' => 'Hamra', 'delivery_fee' => 2, 'minimum_order' => 0, 'is_active' => true,
        ]);
        // Do not register the report number as a driver — inbound would hit the driver bot first.
        $this->staffWroteToShop($this->merchant, self::OWNER, now()->subHour());
    }

    private function receive(array $msg, string $from): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [$msg + ['id' => 'wamid.IN'.(++$this->seq), 'from' => ltrim($from, '+')]],
        ]]]]]]);
    }

    private function say(string $text): void
    {
        $this->receive(['type' => 'text', 'text' => ['body' => $text]], self::CUSTOMER);
    }

    private function ownerTap(string $id): void
    {
        $this->receive([
            'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']],
        ], self::OWNER);
    }

    /** @return list<array{to: string, text: string, buttons: list<string>}> */
    private function messagesTo(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['to'] === $digits)
            ->map(fn (Request $r) => [
                'to' => $r['to'],
                'text' => $r['text']['body'] ?? ($r['interactive']['body']['text'] ?? ''),
                'buttons' => collect($r['interactive']['action']['buttons'] ?? [])->pluck('reply.id')->all(),
            ])->values()->all();
    }

    private function placeWaitingOrder(): Order
    {
        $this->say('add 2 shawarma');
        $this->say('confirm | Hamra | Bliss St, Building 4 | cash');
        $order = Order::query()->withoutGlobalScopes()->where('merchant_id', $this->merchant->id)->latest('number')->first();
        if (! $order) {
            $last = collect($this->messagesTo(self::CUSTOMER))->last()['text'] ?? '(no reply)';
            $this->fail('order not placed; last customer reply: '.$last);
        }

        return $order;
    }

    public function test_new_order_sends_accept_ticket_to_report_whatsapp(): void
    {
        $order = $this->placeWaitingOrder();

        // Not faked: sync queue already ran NotifyShopOfOrder after place.
        $last = collect($this->messagesTo(self::OWNER))->last();
        $this->assertNotNull($last);
        $this->assertStringContainsString('New order #1', $last['text']);
        $this->assertTrue(collect($last['buttons'])->contains(fn ($id) => str_starts_with($id, 'stf:accept:'.$order->id)));
        $this->assertTrue(collect($last['buttons'])->contains(fn ($id) => str_starts_with($id, 'stf:reject:'.$order->id)));
    }

    public function test_owner_accept_button_confirms_the_order(): void
    {
        $order = $this->placeWaitingOrder();
        $this->ownerTap("stf:accept:{$order->id}");

        $this->assertSame('confirmed', $order->fresh()->status);
        $customerLast = collect($this->messagesTo(self::CUSTOMER))->last()['text'] ?? '';
        $this->assertStringContainsString('accepted your order', $customerLast);
    }

    public function test_customer_cannot_use_shop_accept_button(): void
    {
        $order = $this->placeWaitingOrder();
        $this->receive([
            'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "stf:accept:{$order->id}", 'title' => 'x']],
        ], self::CUSTOMER);

        $this->assertSame('placed', $order->fresh()->status);
    }

    public function test_ai_promise_without_checkout_action_still_opens_buttons(): void
    {
        // Real fake provider adds the item; then a stub promises checkout without the action.
        $this->say('add 2 shawarma');

        $test = $this;
        $this->aiScript = [[
            'intent' => 'order', 'confidence' => 1, 'cart_actions' => [],
            'recommended_action' => 'none',
            'assistant_message' => 'تمام، منكمل طلب الاستلام',
        ]];
        $this->app->bind(ChatProvider::class, fn () => new class($test) implements ChatProvider
        {
            public function __construct(private ShopTicketTest $test) {}

            public function processTurn(ConversationTurnInput $input): StructuredTurnOutput
            {
                return StructuredTurnOutput::fromArray($this->test->nextAi());
            }
        });

        // Not a short continue cue — must go through the AI safety net.
        $this->say('هل بقدر استلم من المحل؟');

        $last = collect($this->messagesTo(self::CUSTOMER))->last();
        $this->assertSame(
            ['co:ful:delivery', 'co:ful:pickup'],
            $last['buttons'] ?? [],
            'last reply: '.($last['text'] ?? '(none)')
        );
        $this->assertArrayHasKey('checkout', Conversation::query()->withoutGlobalScopes()->firstOrFail()->state ?? []);
    }

}
