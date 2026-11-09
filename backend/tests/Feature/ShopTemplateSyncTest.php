<?php

namespace Tests\Feature;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The hourly templates:sync sends every connected shop's message templates to Meta. A shop that is
 * suspended (trial over, or stopped by hand) is still connected, and still has to be ready the day it
 * is switched back on, so it is synced too. Cancelled and not yet approved shops are not.
 */
class ShopTemplateSyncTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $status, string $waba): Merchant
    {
        return Merchant::factory()->create([
            'status' => $status,
            'settings' => ['bot' => ['phone_id' => 'PH-'.$waba, 'waba_id' => $waba]],
        ]);
    }

    private function submittedTo(string $waba): bool
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), "{$waba}/message_templates"))->isNotEmpty();
    }

    public function test_the_sync_covers_active_and_suspended_shops_but_not_cancelled_or_pending_ones(): void
    {
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(function (Request $r) {
            if ($r->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            return Http::response(['id' => 'tpl', 'status' => 'PENDING']);
        });
        $this->shop('active', 'WABA-ACTIVE');
        $this->shop('suspended', 'WABA-SUSPENDED');
        $this->shop('cancelled', 'WABA-CANCELLED');
        $this->shop('pending', 'WABA-PENDING');

        $this->artisan('templates:sync')->assertSuccessful();

        $this->assertTrue($this->submittedTo('WABA-ACTIVE'));
        $this->assertTrue($this->submittedTo('WABA-SUSPENDED'));
        $this->assertFalse($this->submittedTo('WABA-CANCELLED'));
        $this->assertFalse($this->submittedTo('WABA-PENDING'));
    }

    public function test_a_suspended_shop_gets_its_missing_templates_saved_as_pending(): void
    {
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['data' => []])
            : Http::response(['id' => 'tpl', 'status' => 'PENDING']));
        $merchant = $this->shop('suspended', 'WABA-SUSPENDED');

        $this->artisan('templates:sync')->assertSuccessful();

        $templates = $merchant->fresh()->settings['bot']['templates'];
        $this->assertSame('PENDING', $templates['neotalab_billing_reminder']['status']);
        $this->assertSame('PENDING', $templates['neotalab_billing_reminder_ar']['status']);
        $this->assertSame('PENDING', $templates['neotalab_c_order_accepted_fr']['status']);
    }
}
