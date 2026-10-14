<?php

namespace Tests\Feature;

use App\Models\WhatsAppMessage;
use App\Services\UsageService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class UsageReportTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_ai_cost_counts_cached_tokens_at_the_cached_price(): void
    {
        // gpt-4.1-mini: 1,000 uncached × $0.40/M + 5,000 cached × $0.10/M + 250 output × $1.60/M
        $cost = app(UsageService::class)->aiCost('gpt-4.1-mini-2025-04-14', 6000, 5000, 250);

        $this->assertEqualsWithDelta(0.0004 + 0.0005 + 0.0004, $cost, 1e-9);
    }

    public function test_owner_sees_ai_and_whatsapp_usage_per_shop(): void
    {
        [$shop] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Foren']);
        $usage = app(UsageService::class);
        $usage->recordAi($shop->id, 'gpt-4.1-mini', ['prompt_tokens' => 6000, 'prompt_tokens_details' => ['cached_tokens' => 5000], 'completion_tokens' => 250]);
        $usage->recordAi($shop->id, 'gpt-4.1-mini', ['prompt_tokens' => 6000, 'prompt_tokens_details' => ['cached_tokens' => 5000], 'completion_tokens' => 250]);

        foreach (range(1, 1003) as $i) {
            WhatsAppMessage::query()->withoutGlobalScopes()->create(['merchant_id' => $shop->id, 'wa_message_id' => 'w'.$i, 'direction' => 'outbound', 'message_type' => 'text']);
        }
        WhatsAppMessage::query()->withoutGlobalScopes()->create(['merchant_id' => $shop->id, 'wa_message_id' => 't1', 'direction' => 'outbound', 'message_type' => 'template']);
        WhatsAppMessage::query()->withoutGlobalScopes()->create(['merchant_id' => $shop->id, 'wa_message_id' => 'in1', 'direction' => 'inbound', 'message_type' => 'text']);

        $row = collect($this->withToken($this->tokenFor($this->platformAdmin()))->getJson('/api/v1/admin/usage')
            ->assertOk()->json('rows'))->firstWhere('merchant_id', $shop->id);

        $this->assertSame(2, $row['ai_requests']);
        $this->assertEqualsWithDelta(0.0026, $row['ai_cost'], 0.0001);
        $this->assertSame(1003, $row['wa_messages'], 'inbound messages are free and not counted');
        $this->assertSame(1, $row['wa_templates']);
        $this->assertEqualsWithDelta(4 * 0.0091, $row['meta_cost'], 0.001, '3 service messages over the free 1,000 + 1 template');
    }

    public function test_merchants_cannot_see_platform_usage(): void
    {
        [, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER);

        $this->withToken($this->tokenFor($owner))->getJson('/api/v1/admin/usage')->assertForbidden();
    }
}
