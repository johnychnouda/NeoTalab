<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Merchant;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
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

class ChatMenuTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Inside the default opening hours (09:00–23:00) whatever time the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class]);
        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Foren', 'currency' => 'USD', 'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto']]);
    }

    private function inbound(array $message): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [array_merge(['id' => 'wamid.IN'.(++$this->seq), 'from' => '96170123456'], $message)],
        ]]]]]]);
    }

    private function tap(string $id): void
    {
        $this->inbound(['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => 'x']]]);
    }

    /** @return array<string, mixed> */
    private function last(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))->last()->data();
    }

    /** @return list<string> */
    private function ids(): array
    {
        $i = $this->last()['interactive'];

        return $i['type'] === 'list'
            ? array_column($i['action']['sections'][0]['rows'], 'id')
            : array_map(fn ($b) => $b['reply']['id'], $i['action']['buttons']);
    }

    private function cartItems()
    {
        return Conversation::query()->withoutGlobalScopes()->firstOrFail()->cart()->withoutGlobalScopes()->first()->items()->withoutGlobalScopes()->get();
    }

    public function test_greeting_comes_with_the_menu_and_big_menus_page_with_more(): void
    {
        foreach (range(1, 12) as $n) {
            $c = Category::factory()->forMerchant($this->merchant)->create(['name' => "Cat {$n}", 'sort_order' => $n]);
            Product::factory()->forMerchant($this->merchant)->create(['name' => "Item {$n}", 'price' => 1, 'category_id' => $c->id]);
        }

        $this->inbound(['type' => 'text', 'text' => ['body' => 'marhaba']]);
        $this->assertSame('list', $this->last()['interactive']['type']);
        $ids = $this->ids();
        $this->assertCount(10, $ids, '9 categories + More');
        $this->assertSame('mn:cats:2', end($ids));

        $this->tap('mn:cats:2');
        $this->assertCount(3, $this->ids(), 'categories 10–12');
    }

    public function test_choose_size_extras_and_quantity_then_checkout(): void
    {
        $pizza = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Pizza', 'price' => 8]);
        $large = ProductVariant::query()->forceCreate(['merchant_id' => $this->merchant->id, 'product_id' => $pizza->id, 'name' => 'Large', 'price' => 10, 'is_active' => true, 'is_available' => true]);
        ProductVariant::query()->forceCreate(['merchant_id' => $this->merchant->id, 'product_id' => $pizza->id, 'name' => 'Small', 'price' => 8, 'is_active' => true, 'is_available' => true]);
        $group = ModifierGroup::query()->forceCreate(['merchant_id' => $this->merchant->id, 'product_id' => $pizza->id, 'name' => 'Extras', 'required' => false, 'min_select' => 0, 'max_select' => 3]);
        $olives = Modifier::query()->forceCreate(['merchant_id' => $this->merchant->id, 'modifier_group_id' => $group->id, 'name' => 'Olives', 'price_delta' => 0.5, 'is_active' => true, 'is_available' => true]);
        Modifier::query()->forceCreate(['merchant_id' => $this->merchant->id, 'modifier_group_id' => $group->id, 'name' => 'Mushrooms', 'price_delta' => 0.5, 'is_active' => true, 'is_available' => true]);

        $this->tap("mn:item:{$pizza->id}");
        $this->assertContains("mn:var:{$large->id}", $this->ids(), 'size first');

        $this->tap("mn:var:{$large->id}");
        $this->assertContains("mn:mod:{$olives->id}", $this->ids());
        $this->assertContains('mn:done', $this->ids());

        $this->tap("mn:mod:{$olives->id}");
        $this->assertNotContains("mn:mod:{$olives->id}", $this->ids(), 'can add another extra');
        $this->tap('mn:done');
        $this->assertSame(['mn:qty:1', 'mn:qty:2', 'mn:qtymore'], $this->ids());

        $this->tap('mn:qtymore');
        $this->tap('mn:qty:4');
        $this->assertStringContainsString('Added 4× Pizza', $this->last()['interactive']['body']['text']);
        $this->assertStringContainsString('Cart: 4 items · 42.00$', $this->last()['interactive']['body']['text']);
        $this->assertSame(['mn:cats:1', 'co:start:'], $this->ids());

        $item = $this->cartItems()->first();
        $this->assertSame($large->id, $item->variant_id);
        $this->assertSame([$olives->id], $item->modifier_ids);

        $this->tap('co:start:');
        // Delivery only, no areas: checkout asks for the location right away.
        $this->assertSame('location_request_message', $this->last()['interactive']['type']);
    }

    public function test_sold_out_items_are_not_in_the_menu(): void
    {
        $gone = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Lahm', 'price' => 2, 'is_available' => false]);
        $zaatar = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Zaatar', 'price' => 1]);

        $this->tap('mn:cats:1');
        $this->assertSame(["mn:item:{$zaatar->id}"], $this->ids());

        // A tap on an older list after the item sold out: refused, with the current list.
        $this->tap("mn:item:{$gone->id}");

        $this->assertStringContainsString('Lahm is sold out', $this->last()['interactive']['body']['text']);
        $this->assertSame('list', $this->last()['interactive']['type']);
    }

    public function test_offers_come_first_with_the_discounted_price_in_the_cart(): void
    {
        $c = Category::factory()->forMerchant($this->merchant)->create(['name' => 'Pizza']);
        Category::factory()->forMerchant($this->merchant)->create(['name' => 'Drinks']);
        $deal = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Margherita', 'price' => 10, 'category_id' => $c->id,
            'offer_percent' => 20, 'offer_starts_on' => now()->subDay()->toDateString(), 'offer_ends_on' => now()->addDay()->toDateString()]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Old deal', 'price' => 10, 'category_id' => $c->id,
            'offer_percent' => 50, 'offer_ends_on' => now()->subDays(2)->toDateString()]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Cola', 'price' => 1, 'category_id' => Category::query()->withoutGlobalScopes()->where('name', 'Drinks')->value('id')]);

        $this->tap('mn:cats:1');
        $rows = $this->last()['interactive']['action']['sections'][0]['rows'];
        $this->assertSame('mn:cat:offers:1', $rows[0]['id']);
        $this->assertSame('🔥 Offers', $rows[0]['title']);
        $this->assertSame('1 item', $rows[0]['description'], 'expired offers do not count');

        $this->tap('mn:cat:offers:1');
        $this->assertSame('8.00$ (was 10.00$) 🔥', $this->last()['interactive']['action']['sections'][0]['rows'][0]['description']);

        $this->tap("mn:item:{$deal->id}");
        $this->tap('mn:qty:2');
        $this->assertEquals(8, $this->cartItems()->first()->unit_price);
    }

    public function test_items_sold_by_weight_show_the_unit(): void
    {
        $tomato = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Tomatoes', 'price' => 1.5, 'unit' => 'kg']);

        $this->tap('mn:cats:1');
        $this->assertSame('1.50$ / kg', $this->last()['interactive']['action']['sections'][0]['rows'][0]['description']);

        $this->tap("mn:item:{$tomato->id}");
        $this->assertSame(['1 kg', '2 kg', '3+ kg'], array_map(fn ($b) => $b['reply']['title'], $this->last()['interactive']['action']['buttons']));
    }
}
