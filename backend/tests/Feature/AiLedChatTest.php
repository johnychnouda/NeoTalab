<?php

namespace Tests\Feature;

use App\Jobs\CheckLateOrder;
use App\Jobs\CheckUnacceptedOrder;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Services\AI\Contracts\ChatProvider;
use App\Services\AI\DTO\ConversationTurnInput;
use App\Services\AI\DTO\StructuredTurnOutput;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The assistant leads the chat: the greeting and a request for the menu are answered in words (no
 * menu list to tap), and when the assistant itself fails the customer is told a person will answer
 * and the team is alerted, instead of a menu being shown.
 */
class AiLedChatTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, \Tests\Concerns\ShopPhone;

    private Merchant $merchant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
        Bus::fake([CheckUnacceptedOrder::class, CheckLateOrder::class, NotifyStaffOfHandoff::class, \App\Jobs\NotifyShopOfOrder::class]);
        Cache::flush();
        [$this->merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Foren', 'currency' => 'USD', 'settings' => ['bot' => ['phone_id' => '555'], 'mode' => 'auto']]);
    }

    private function say(string $text): void
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '555'],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => '96170123456', 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
    }

    /** @return array<string, mixed> */
    private function last(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))->last()->data();
    }

    private function menu(int $categories): void
    {
        foreach (range(1, $categories) as $n) {
            $c = Category::factory()->forMerchant($this->merchant)->create(['name' => "Cat {$n}", 'sort_order' => $n]);
            Product::factory()->forMerchant($this->merchant)->create(['name' => "Item {$n}", 'price' => 1, 'category_id' => $c->id]);
        }
    }

    public function test_the_greeting_is_a_short_human_hello_without_the_menu(): void
    {
        $this->menu(3);

        $this->say('hello');

        $sent = $this->last();
        $this->assertSame('text', $sent['type']);
        $this->assertStringContainsString('What can I get you', $sent['text']['body']);
        $this->assertStringNotContainsString('Cat 1', $sent['text']['body']);
        $this->assertStringNotContainsString('•', $sent['text']['body']);
    }

    public function test_asking_for_the_menu_lists_categories_in_words(): void
    {
        $this->menu(12);

        $this->say('show me the menu');

        $body = $this->last()['text']['body'];
        $this->assertStringContainsString('Cat 10', $body);
        $this->assertStringNotContainsString('Cat 11', $body);
        $this->assertStringContainsString('2 more', $body);
    }

    public function test_when_the_assistant_fails_the_customer_hears_a_person_will_answer(): void
    {
        $this->menu(2);
        $this->app->bind(ChatProvider::class, fn () => new class implements ChatProvider
        {
            public function processTurn(ConversationTurnInput $input): StructuredTurnOutput
            {
                throw new \RuntimeException('provider down');
            }
        });

        $this->say('2 item 1 please');
        $first = $this->last();
        $this->say('hello?');

        $this->assertSame('text', $first['type']);
        $this->assertStringContainsString('team member from Foren', $first['text']['body']);
        $this->assertStringNotContainsString('Cat 1', $first['text']['body']);
    }
}
