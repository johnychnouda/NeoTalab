<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\DTO\ConversationTurnInput;
use App\Services\AI\DTO\StructuredTurnOutput;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * An item that needs a size or a required extra is asked for in words, never with a list to tap. The
 * cart still refuses the item until the choice is valid, so the assistant cannot say "added" for it, and
 * the question is what the conversation remembers.
 */
class AiChoicesInWordsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CUSTOMER = '96170123456';

    private Merchant $merchant;

    private Product $pizza;

    private ProductVariant $small;

    private ProductVariant $large;

    /** @var list<array<string, mixed>> */
    private array $script = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Foren', 'currency' => 'USD', 'timezone' => 'Asia/Beirut',
            'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto', 'fulfillment' => 'pickup'],
        ]);
        $this->pizza = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Pizza', 'price' => 8]);
        $this->small = ProductVariant::query()->forceCreate(['merchant_id' => $this->merchant->id, 'product_id' => $this->pizza->id, 'name' => 'Small', 'price' => 8, 'is_active' => true, 'is_available' => true]);
        $this->large = ProductVariant::query()->forceCreate(['merchant_id' => $this->merchant->id, 'product_id' => $this->pizza->id, 'name' => 'Large', 'price' => 10, 'is_active' => true, 'is_available' => true]);

        $test = $this;
        $this->app->bind(ChatProvider::class, fn () => new class($test) implements ChatProvider
        {
            public function __construct(private AiChoicesInWordsTest $test) {}

            public function processTurn(ConversationTurnInput $input): StructuredTurnOutput
            {
                return StructuredTurnOutput::fromArray($this->test->nextOutput());
            }
        });
    }

    public function nextOutput(): array
    {
        return array_shift($this->script) ?? ['recommended_action' => 'none', 'assistant_message' => 'ok'];
    }

    private function ai(array $actions, string $says = 'Added!'): void
    {
        $this->script[] = ['intent' => 'order', 'confidence' => 1, 'cart_actions' => $actions, 'recommended_action' => 'none', 'assistant_message' => $says, 'checkout' => null];
    }

    private function add(Product $product, array $extra = []): array
    {
        return array_merge(['action' => 'add', 'product_id' => $product->id, 'quantity' => 1, 'modifier_ids' => []], $extra);
    }

    private function say(string $text): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => self::CUSTOMER, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    /** @return array<string, mixed> */
    private function last(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))->last()->data();
    }

    private function cartCount(): int
    {
        $customer = Customer::query()->withoutGlobalScopes()->where('phone', '+'.self::CUSTOMER)->first();
        $cart = $customer ? Cart::query()->withoutGlobalScopes()->whereHas('conversation', fn ($q) => $q->withoutGlobalScopes()->where('customer_id', $customer->id))->first() : null;

        return $cart ? $cart->items()->withoutGlobalScopes()->count() : 0;
    }

    public function test_a_missing_size_is_asked_in_words_with_the_prices_and_nothing_is_added(): void
    {
        $this->ai([$this->add($this->pizza)]);

        $this->say('a pizza please');

        $sent = $this->last();
        $this->assertSame('text', $sent['type'], 'no list to tap');
        $this->assertStringContainsString('Pizza', $sent['text']['body']);
        $this->assertStringContainsString('Small (8.00$)', $sent['text']['body']);
        $this->assertStringContainsString('Large (10.00$)', $sent['text']['body']);
        $this->assertStringNotContainsString('Added', $sent['text']['body']);
        $this->assertSame(0, $this->cartCount());
    }

    public function test_the_question_is_what_the_conversation_remembers_and_the_answer_adds_the_item(): void
    {
        $this->ai([$this->add($this->pizza)]);
        $this->say('a pizza please');

        $messages = Conversation::query()->withoutGlobalScopes()->firstOrFail()->state['messages'];
        $this->assertStringContainsString('Large (10.00$)', end($messages)['content']);

        $this->ai([$this->add($this->pizza, ['variant_id' => $this->large->id])], 'One large pizza, added.');
        $this->say('large');

        $this->assertSame(1, $this->cartCount());
        $this->assertStringContainsString('One large pizza', $this->last()['text']['body']);
    }

    public function test_a_required_extra_is_asked_in_words_too(): void
    {
        $wrap = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Wrap', 'price' => 5]);
        $sauce = ModifierGroup::factory()->forProduct($wrap)->create(['name' => 'Sauce', 'required' => true, 'min_select' => 1, 'max_select' => 1]);
        Modifier::factory()->forGroup($sauce)->create(['name' => 'Garlic', 'price_delta' => 0]);
        Modifier::factory()->forGroup($sauce)->create(['name' => 'Spicy', 'price_delta' => 0.5]);
        $this->ai([$this->add($wrap)]);

        $this->say('a wrap');

        $body = $this->last()['text']['body'];
        $this->assertSame('text', $this->last()['type']);
        $this->assertStringContainsString('Sauce', $body);
        $this->assertStringContainsString('Garlic', $body);
        $this->assertStringContainsString('Spicy (+0.50$)', $body);
        $this->assertSame(0, $this->cartCount());
    }

    public function test_a_sold_out_item_is_still_refused_in_words(): void
    {
        $gone = Product::factory()->forMerchant($this->merchant)->create(['name' => 'Lahm', 'price' => 2, 'is_available' => false]);
        $this->ai([$this->add($gone)]);

        $this->say('lahm');

        $this->assertStringContainsString('available anymore', $this->last()['text']['body']);
        $this->assertSame(0, $this->cartCount());
    }
}
