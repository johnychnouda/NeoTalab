<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\AI\DTO\CartAction;
use App\Services\CartService;
use App\Services\ConversationService;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\CurrentTenant;
use App\Support\OrderMessage;
use App\Support\Quantity;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FillsTheCart;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Groceries, butchers, laundries: items sold by the kilo, in quarter-kilo steps. */
class WeightTest extends TestCase
{
    use FillsTheCart, InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    private Product $tomatoes;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Green Market', 'currency' => 'USD', 'timezone' => 'Asia/Beirut', 'business_type' => 'Supermarket / Grocery',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'fulfillment' => 'pickup'],
        ]);
        $this->tomatoes = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Tomatoes', 'price' => 1.5, 'unit' => 'kg']);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Bread', 'price' => 1, 'unit' => 'pack']);
    }

    private function receive(array $msg): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => '96170123456'] + $msg],
        ]]]]]]);
    }

    private function say(string $text): void
    {
        $this->receive(['type' => 'text', 'text' => ['body' => $text]]);
    }

    private function line(): ?CartItem
    {
        return CartItem::query()->withoutGlobalScopes()->where('product_id', $this->tomatoes->id)->latest('id')->first();
    }

    public function test_half_a_kilo_is_kept_and_priced_per_kilo(): void
    {
        $this->putInCart($this->merchant, '96170123456', $this->tomatoes, 0.5);

        $this->assertSame(0.5, $this->line()->quantity);
        $this->assertSame('0.75', number_format((float) $this->line()->line_total, 2, '.', ''));
    }

    public function test_weights_round_to_a_quarter_kilo_and_pieces_stay_whole(): void
    {
        app(CurrentTenant::class)->set($this->merchant);
        $cart = app(ConversationService::class)->startOrResume(['phone' => '+96170555555'])->cart;
        $bread = Product::query()->where('name', 'Bread')->sole();

        $result = app(CartService::class)->applyActions($cart, [
            new CartAction(action: 'add', productId: $this->tomatoes->id, quantity: 1.3),
            new CartAction(action: 'add', productId: $this->tomatoes->id, quantity: 0.01),
            new CartAction(action: 'add', productId: $bread->id, quantity: 2.4),
        ]);
        // The reply is told which amounts the cart changed, so the customer reads 1.25 kg and not the 1.3 asked for.
        $this->assertSame([['name' => 'Tomatoes', 'quantity' => 1.25, 'unit' => 'kg'], ['name' => 'Tomatoes', 'quantity' => 0.25, 'unit' => 'kg']], $result['rounded']);
        $quantities = $cart->items()->orderBy('created_at')->get()->map(fn ($i) => [$i->product->name, $i->quantity])->all();
        $this->assertEqualsCanonicalizing([['Tomatoes', 1.25], ['Tomatoes', 0.25], ['Bread', 2]], $quantities);
        // 1.25 kg × 1.50$ = 1.88$, 0.25 kg = 0.38$ (each line rounded to the cent), 2 breads = 2.00$.
        $this->assertSame('4.26', number_format((float) $cart->fresh()->subtotal, 2, '.', ''));
    }

    public function test_the_order_ticket_says_kg(): void
    {
        $this->putInCart($this->merchant, '96170123456', $this->tomatoes, 1.5);
        $this->say('confirm | | | cash');

        $order = Order::query()->withoutGlobalScopes()->sole();
        $item = OrderItem::query()->withoutGlobalScopes()->sole();
        $this->assertSame(1.5, $item->quantity);
        $this->assertSame('kg', $item->unit);
        $this->assertSame('2.25', number_format((float) $order->subtotal, 2, '.', ''));
        $this->assertStringContainsString('• 1.5 kg Tomatoes', OrderMessage::params($order, $this->merchant)['items']);
    }

    public function test_quantity_helper(): void
    {
        $this->assertSame('1.5', Quantity::format(1.5));
        $this->assertSame('2', Quantity::format('2.000'));
        $this->assertSame(0.75, Quantity::normalize(0.8, $this->tomatoes));
        $this->assertSame(99, Quantity::normalize(5000, $this->tomatoes));
        $this->assertSame('2× Bread', Quantity::line(2, null, 'Bread'));
    }
}
