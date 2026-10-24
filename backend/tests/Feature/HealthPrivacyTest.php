<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\WhatsAppMessage;
use App\Services\AI\Providers\OpenAiCompatibleChatProvider;
use App\Services\WhatsApp\WhatsAppInboundService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Pharmacies and clinics: no medical details asked, a note on the first reply, old chat text erased. */
class HealthPrivacyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)]);
    }

    private function shop(string $type, string $phoneId): Merchant
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => $type, 'business_type' => $type, 'timezone' => 'Asia/Beirut',
            'settings' => ['bot' => ['phone_id' => $phoneId], 'mode' => 'auto'],
        ]);
        Product::factory()->forMerchant($merchant)->create(['name' => 'Item', 'price' => 5]);

        return $merchant;
    }

    private function say(string $phoneId, string $text, string $from = '96170123456'): string
    {
        app(WhatsAppInboundService::class)->handle(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId],
            'messages' => [['id' => 'wamid.IN'.(++$this->seq), 'from' => $from, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);
        $last = Http::recorded()->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/messages'))->last();

        return $last['text']['body'] ?? ($last['interactive']['body']['text'] ?? '');
    }

    public function test_the_first_reply_of_a_clinic_or_pharmacy_asks_for_no_medical_details(): void
    {
        $this->shop('Clinic / Dentist', '601');
        $this->shop('Pharmacy', '602');
        $this->shop('Restaurant / Café', '603');

        $this->assertStringContainsString("don't send medical details", $this->say('601', 'hi'));
        $this->assertStringContainsString("don't send medical details", $this->say('602', 'hi'));
        $this->assertStringNotContainsString('medical', $this->say('603', 'hi'));
        $this->assertStringNotContainsString('medical', $this->say('601', 'menu'), 'only once per chat');
    }

    public function test_the_assistant_is_told_never_to_ask_for_health_details(): void
    {
        $this->shop('Clinic / Dentist', '601');
        config(['ai.driver' => 'openai', 'ai.openai.api_key' => 'k']);
        Http::fake([
            'api.openai.com/*' => Http::response(['model' => 'm', 'usage' => [], 'choices' => [['message' => ['content' => json_encode([
                'intent' => 'greeting', 'confidence' => 1, 'entities' => [], 'cart_actions' => [], 'conversation_state' => ['phase' => 'idle'],
                'missing_fields' => [], 'recommended_action' => 'none', 'assistant_message' => 'Hello', 'checkout' => null, 'appointment' => null,
            ])]]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
        ]);
        $this->app->forgetInstance(OpenAiCompatibleChatProvider::class);
        $this->say('601', 'hello');

        $sent = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), 'openai'));
        $this->assertNotNull($sent);
        $this->assertStringContainsString('never ask about symptoms', $sent['messages'][0]['content']);
        $this->assertStringContainsString('"health_privacy":true', $sent['messages'][1]['content']);
    }

    public function test_old_chat_text_of_health_shops_is_erased_and_other_shops_keep_theirs(): void
    {
        $clinic = $this->shop('Clinic / Dentist', '601');
        $food = $this->shop('Restaurant / Café', '603');
        $this->say('601', 'I have a toothache since Monday');
        $this->say('603', 'hi');

        Carbon::setTestNow(now()->addDays(31));
        $this->artisan('chats:forget')->assertSuccessful();

        $clinicChat = Conversation::query()->withoutGlobalScopes()->where('merchant_id', $clinic->id)->sole();
        $this->assertSame([], $clinicChat->state['messages']);
        $this->assertStringNotContainsString('toothache', json_encode(WhatsAppMessage::query()->withoutGlobalScopes()->where('merchant_id', $clinic->id)->pluck('payload')));
        $this->assertSame(1, WhatsAppMessage::query()->withoutGlobalScopes()->where('merchant_id', $clinic->id)->where('direction', 'inbound')->count(), 'the row stays for duplicates');
        $this->assertNotEmpty(Conversation::query()->withoutGlobalScopes()->where('merchant_id', $food->id)->sole()->state['messages']);
        $this->assertNotNull(Customer::query()->withoutGlobalScopes()->where('merchant_id', $clinic->id)->first(), 'the customer record stays');
    }
}
