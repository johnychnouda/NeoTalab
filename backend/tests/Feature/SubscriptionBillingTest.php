<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Services\SubscriptionStopService;
use App\Support\Roles;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SubscriptionBillingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlatformSettingsSeeder::class);
    }

    public function test_a_paid_shop_is_stopped_only_after_the_grace_period(): void
    {
        $late = Merchant::factory()->create(['status' => 'active', 'subscription_ends_at' => now()->subDays(4), 'settings' => ['subscription_status' => 'paid']]);
        $inGrace = Merchant::factory()->create(['status' => 'active', 'subscription_ends_at' => now()->subDays(2), 'settings' => ['subscription_status' => 'paid']]);

        $this->assertSame(1, app(SubscriptionStopService::class)->stopDue());

        $this->assertSame('suspended', $late->fresh()->status);
        $this->assertSame('pending', $late->fresh()->settings['subscription_status']);
        $this->assertSame('active', $inGrace->fresh()->status);
    }

    public function test_marking_a_shop_paid_sets_the_period_end_a_month_or_a_year_ahead(): void
    {
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['billing_cycle' => 'monthly']);

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
            'subscriptionStatus' => 'paid', 'lastPaymentAt' => '2026-10-01', 'status' => 'active',
        ])->assertOk();
        $this->assertSame('2026-11-01', $merchant->fresh()->subscription_ends_at->toDateString());

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
            'billingCycle' => 'yearly', 'subscriptionStatus' => 'paid', 'lastPaymentAt' => '2026-10-01',
        ])->assertOk();
        $this->assertSame('2027-10-01', $merchant->fresh()->subscription_ends_at->toDateString());
    }

    public function test_the_owner_name_can_be_set_from_the_panel(): void
    {
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Foren Shop']);

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['ownerName' => 'Hassan Ali'])->assertOk();

        $this->assertSame('Hassan Ali', $owner->fresh()->name);
        $this->withToken($token)->getJson("/api/v1/admin/merchants/{$merchant->id}")->assertJsonPath('data.owner_name', 'Hassan Ali');
    }

    public function test_the_shop_map_link_comes_from_its_pin(): void
    {
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        $settings = $merchant->settings ?? [];
        $settings['shop_location'] = '33.8938, 35.5018';
        $merchant->forceFill(['settings' => $settings])->save();

        $this->withToken($token)->getJson("/api/v1/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->assertJsonPath('data.map_url', 'https://www.google.com/maps?q=33.8938,35.5018');
    }

    public function test_a_fee_change_is_recorded_in_the_history(): void
    {
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER);

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['monthlyFee' => 30])->assertOk();
        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['monthlyFee' => 40])->assertOk();

        $history = $merchant->fresh()->settings['fee_history'];
        $this->assertCount(2, $history);
        $this->assertEquals(40, $history[1]['monthly_fee']);
    }

    public function test_the_owner_is_confirmed_by_an_approved_template_only(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], ['wa_phone_id' => '111222333', 'wa_access_token' => 'EAAX_platform']);
        $platform->save();
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['whatsapp_number' => '+96171112233', 'phone' => '+96171112233']);
        $payload = ['subscriptionStatus' => 'paid', 'lastPaymentAt' => '2026-10-01', 'status' => 'active'];

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", $payload)->assertOk();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '111222333/messages'));

        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], ['wa_templates' => ['neotalab_paid' => 'APPROVED']]);
        $platform->save();
        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['lastPaymentAt' => '2026-11-01'] + $payload)->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), '111222333/messages') && $r['template']['name'] === 'neotalab_paid');
    }
}
