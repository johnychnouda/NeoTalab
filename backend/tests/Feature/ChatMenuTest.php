<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Merchant;
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
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class, \App\Jobs\NotifyShopOfOrder::class]);
        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Foren', 'currency' => 'USD', 'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto']]);
    }

    private function inbound(array $message): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [array_merge(['id' => 'wamid.IN'.(++$this->seq), 'from' => '96170123456'], $message)],
        ]]]]]]);
    }

    private function say(string $text): void
    {
        $this->inbound(['type' => 'text', 'text' => ['body' => $text]]);
    }

    /** @return array<string, mixed> */
    private function last(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))->last()->data();
    }

    private function cartItems()
    {
        return Conversation::query()->withoutGlobalScopes()->firstOrFail()->cart()->withoutGlobalScopes()->first()->items()->withoutGlobalScopes()->get();
    }

    public function test_a_named_section_shows_its_items_not_the_list_of_sections_again(): void
    {
        $pizza = Category::factory()->forMerchant($this->merchant)->create(['name' => 'Pizza', 'sort_order' => 1]);
        $burgers = Category::factory()->forMerchant($this->merchant)->create(['name' => 'Burgers', 'sort_order' => 2]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Margherita', 'price' => 8, 'category_id' => $pizza->id]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Pepperoni', 'price' => 10, 'category_id' => $pizza->id]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Classic burger', 'price' => 6, 'category_id' => $burgers->id]);

        $this->say('menu pizza section please');

        $text = $this->last()['text']['body'];
        $this->assertStringContainsString('• Margherita — 8.00$', $text);
        $this->assertStringContainsString('• Pepperoni — 10.00$', $text);
        $this->assertStringNotContainsString('Burgers —', $text, 'the sections are not listed again');

        $this->say('menu');
        $this->assertStringContainsString('Burgers — 1 item', $this->last()['text']['body'], 'no section named: the list of sections');
    }

    public function test_the_cart_is_shown_with_prices_after_an_add(): void
    {
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Shawarma', 'price' => 5]);

        $this->say('add 2 shawarma');

        $text = $this->last()['text']['body'];
        $this->assertStringContainsString('Your cart:', $text);
        $this->assertStringContainsString('• 2× Shawarma — 10.00$', $text);
        $this->assertStringContainsString('Subtotal: 10.00$', $text);
    }

    public function test_asking_for_the_menu_lists_categories_in_words(): void
    {
        foreach (range(1, 12) as $n) {
            $c = Category::factory()->forMerchant($this->merchant)->create(['name' => "Cat {$n}", 'sort_order' => $n]);
            Product::factory()->forMerchant($this->merchant)->create(['name' => "Item {$n}", 'price' => 1, 'category_id' => $c->id]);
        }

        $this->say('menu');

        $this->assertSame('text', $this->last()['type']);
        $this->assertStringContainsString('Cat 10', $this->last()['text']['body']);
        $this->assertStringNotContainsString('Cat 11', $this->last()['text']['body']);
        $this->assertStringContainsString('2 more', $this->last()['text']['body']);
    }

    public function test_sold_out_items_are_not_in_the_menu(): void
    {
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Lahm', 'price' => 2, 'is_available' => false]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Zaatar', 'price' => 1]);

        $this->say('menu');

        $this->assertStringContainsString('Zaatar', $this->last()['text']['body']);
        $this->assertStringNotContainsString('Lahm', $this->last()['text']['body']);
    }

    public function test_offers_come_first_with_the_discounted_price_in_the_cart(): void
    {
        $c = Category::factory()->forMerchant($this->merchant)->create(['name' => 'Pizza']);
        Category::factory()->forMerchant($this->merchant)->create(['name' => 'Drinks']);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Margherita', 'price' => 10, 'category_id' => $c->id,
            'offer_percent' => 20, 'offer_starts_on' => now()->subDay()->toDateString(), 'offer_ends_on' => now()->addDay()->toDateString()]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Old deal', 'price' => 10, 'category_id' => $c->id,
            'offer_percent' => 50, 'offer_ends_on' => now()->subDays(2)->toDateString()]);
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Cola', 'price' => 1, 'category_id' => Category::query()->withoutGlobalScopes()->where('name', 'Drinks')->value('id')]);

        $this->say('menu');
        $lines = explode("\n", $this->last()['text']['body']);
        $menu = array_values(array_filter($lines, fn ($l) => str_starts_with($l, '•')));
        $this->assertSame('• 🔥 Offers — 1 item', $menu[0], 'expired offers do not count');

        $this->say('add 2 Margherita');
        $this->assertEquals(8, $this->cartItems()->first()->unit_price);
    }

    public function test_items_sold_by_weight_show_the_unit(): void
    {
        Product::factory()->forMerchant($this->merchant)->create(['name' => 'Tomatoes', 'price' => 1.5, 'unit' => 'kg']);

        $this->say('hello');

        $this->assertStringContainsString('Tomatoes — 1.50$ / kg', $this->last()['text']['body']);
    }
}
