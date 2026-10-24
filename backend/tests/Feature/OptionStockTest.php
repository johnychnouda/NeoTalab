<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Merchant;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Services\OrderService;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Clothing and shoes: stock per size and colour (M in Red), set up in the dashboard, used on WhatsApp. */
class OptionStockTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    private string $token;

    private Product $shirt;

    private ProductVariant $m;

    private ProductVariant $l;

    private Modifier $red;

    private Modifier $blue;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
        [$this->merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Boutique', 'currency' => 'USD', 'timezone' => 'Asia/Beirut', 'business_type' => 'Clothing / Shoes',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'fulfillment' => 'pickup'],
        ]);
        $this->token = $this->tokenFor($owner);
        $this->shirt = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shirt', 'price' => 20, 'track_inventory' => true, 'stock_quantity' => 10]);
        $this->m = ProductVariant::factory()->forProduct($this->shirt)->create(['name' => 'M', 'price' => 20]);
        $this->l = ProductVariant::factory()->forProduct($this->shirt)->create(['name' => 'L', 'price' => 20]);
        $colour = ModifierGroup::factory()->forProduct($this->shirt)->create(['name' => 'Colour', 'required' => true, 'min_select' => 1, 'max_select' => 1]);
        $this->red = Modifier::factory()->forGroup($colour)->create(['name' => 'Red', 'price_delta' => 0]);
        $this->blue = Modifier::factory()->forGroup($colour)->create(['name' => 'Blue', 'price_delta' => 0]);
        ProductStock::query()->forceCreate(['merchant_id' => $this->merchant->id, 'product_id' => $this->shirt->id, 'variant_id' => $this->m->id, 'modifier_id' => $this->red->id, 'stock_quantity' => 1]);
    }

    private function receive(array $msg, string $from = '96170123456'): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => $from] + $msg],
        ]]]]]]);
    }

    private function tap(string $id, string $from = '96170123456'): void
    {
        $this->receive(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']]], $from);
    }

    /** @return list<string> */
    private function lastIds(): array
    {
        $i = Http::recorded()->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))->last()['interactive'] ?? [];

        return array_merge(array_map(fn ($b) => $b['reply']['id'], $i['action']['buttons'] ?? []), array_map(fn ($r) => $r['id'], $i['action']['sections'][0]['rows'] ?? []));
    }

    private function buy(string $from, ProductVariant $size, Modifier $colour): void
    {
        $this->tap("mn:item:{$this->shirt->id}", $from);
        $this->tap("mn:var:{$size->id}", $from);
        $this->tap("mn:mod:{$colour->id}", $from);
        $this->tap('mn:qty:1', $from);
        $this->receive(['type' => 'text', 'text' => ['body' => 'confirm | | | cash']], $from);
    }

    public function test_the_last_medium_red_is_sold_once_then_red_is_not_offered_in_medium(): void
    {
        $this->buy('96170000001', $this->m, $this->red);
        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertSame(0, ProductStock::query()->withoutGlobalScopes()->sole()->stock_quantity);
        $this->assertSame(9, $this->shirt->fresh()->stock_quantity);

        $this->tap("mn:item:{$this->shirt->id}", '96170000002');
        $this->tap("mn:var:{$this->m->id}", '96170000002');
        $this->assertNotContains("mn:mod:{$this->red->id}", $this->lastIds(), 'no Red left in M');
        $this->assertContains("mn:mod:{$this->blue->id}", $this->lastIds());

        // Large has no own number for Red: the item's stock decides.
        $this->buy('96170000003', $this->l, $this->red);
        $this->assertSame(2, Order::query()->withoutGlobalScopes()->count());

        app(OrderService::class)->transition($this->merchant, $order->id, 'cancel', ['reason' => null, 'by' => 'shop']);
        $this->assertSame(1, ProductStock::query()->withoutGlobalScopes()->sole()->stock_quantity, 'a cancelled order gives it back');
    }

    public function test_a_sold_out_combination_stops_the_checkout_even_if_it_was_in_the_cart(): void
    {
        $this->tap("mn:item:{$this->shirt->id}");
        $this->tap("mn:var:{$this->m->id}");
        $this->tap("mn:mod:{$this->red->id}");
        $this->tap('mn:qty:1');
        $this->assertSame(1, CartItem::query()->withoutGlobalScopes()->count());

        $this->buy('96170000009', $this->m, $this->red); // someone else takes the last one
        $this->receive(['type' => 'text', 'text' => ['body' => 'confirm | | | cash']]);

        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_the_shop_sets_stock_per_size_and_colour_in_the_dashboard(): void
    {
        $this->withToken($this->token)->putJson("/api/v1/products/{$this->shirt->id}/stock", [
            'track_inventory' => true, 'stock_quantity' => 12,
            'variants' => [['id' => $this->m->id, 'stock_quantity' => 5]],
            'options' => [
                ['variant_id' => $this->m->id, 'modifier_id' => $this->red->id, 'stock_quantity' => null],
                ['variant_id' => $this->l->id, 'modifier_id' => $this->blue->id, 'stock_quantity' => 3],
            ],
        ])->assertOk()->assertJsonPath('data.option_stocks.0.stock_quantity', 3);

        $this->assertSame(12, $this->shirt->fresh()->stock_quantity);
        $this->assertSame(5, $this->m->fresh()->stock_quantity);
        $this->assertSame([[$this->l->id, $this->blue->id]], ProductStock::query()->withoutGlobalScopes()->get()->map(fn ($r) => [$r->variant_id, $r->modifier_id])->all());

        [$other] = $this->merchantWithUser();
        $foreign = Modifier::factory()->forGroup(ModifierGroup::factory()->forProduct(Product::factory()->forMerchant($other)->create())->create())->create();
        $this->withToken($this->token)->putJson("/api/v1/products/{$this->shirt->id}/stock", [
            'track_inventory' => true, 'stock_quantity' => 1, 'options' => [['variant_id' => null, 'modifier_id' => $foreign->id, 'stock_quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_another_shop_cannot_change_this_stock(): void
    {
        [, $otherOwner] = $this->merchantWithUser();
        $this->withToken($this->tokenFor($otherOwner))->putJson("/api/v1/products/{$this->shirt->id}/stock", ['track_inventory' => false])->assertNotFound();
        $this->assertTrue($this->shirt->fresh()->track_inventory);
    }
}
