<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PlatformSetting;
use App\Services\SubscriptionStopService;
use App\Support\Roles;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_a_recorded_payment_sets_the_period_end_a_month_or_a_year_ahead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00', 'Asia/Beirut'));
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['billing_cycle' => 'monthly', 'settings' => ['monthly_fee' => 25, 'yearly_fee' => 250]]);

        $this->recordWhishPayment($token, $merchant)->assertCreated();
        $this->assertSame('2026-11-01', $merchant->fresh()->subscription_ends_at->toDateString());

        // The next payment is for a year, and it starts after the month already paid.
        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['nextBillingCycle' => 'yearly'])->assertOk();
        $this->recordWhishPayment($token, $merchant)->assertCreated();
        $this->assertSame('2027-11-01', $merchant->fresh()->subscription_ends_at->toDateString());
    }

    public function test_the_cycle_choice_applies_at_once_when_nothing_paid_is_running(): void
    {
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['billing_cycle' => 'monthly']);

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['nextBillingCycle' => 'yearly'])->assertOk();
        $merchant->refresh();
        $this->assertSame('yearly', $merchant->billing_cycle);
        $this->assertArrayNotHasKey('next_billing_cycle', $merchant->settings ?? []);
        $this->assertNull($merchant->subscription_ends_at, 'switching does not mark the shop paid');

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['nextBillingCycle' => 'monthly'])->assertOk();
        $this->assertSame('monthly', $merchant->fresh()->billing_cycle, 'before any payment the shop can go back');
    }

    public function test_in_a_paid_month_a_switch_to_yearly_waits_for_the_next_payment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00', 'Asia/Beirut'));
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['billing_cycle' => 'monthly', 'settings' => ['monthly_fee' => 25, 'yearly_fee' => 250]]);
        $this->recordWhishPayment($token, $merchant)->assertCreated();

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['nextBillingCycle' => 'yearly'])
            ->assertOk()
            ->assertJsonPath('merchant.billing_cycle', 'monthly')
            ->assertJsonPath('merchant.next_billing_cycle', 'yearly')
            ->assertJsonPath('merchant.cycle_locked_until', null);

        // Changing the mind before paying cancels the switch.
        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['nextBillingCycle' => 'monthly'])
            ->assertOk()
            ->assertJsonPath('merchant.next_billing_cycle', null);

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['nextBillingCycle' => 'yearly'])->assertOk();
        $this->recordWhishPayment($token, $merchant)->assertCreated();

        $merchant->refresh();
        $this->assertSame('yearly', $merchant->billing_cycle);
        $this->assertArrayNotHasKey('next_billing_cycle', $merchant->settings);
        $this->assertSame('2027-11-01', $merchant->subscription_ends_at->toDateString());
    }

    public function test_a_paid_year_cannot_be_switched_back_to_monthly_until_it_ends(): void
    {
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['billing_cycle' => 'yearly', 'settings' => ['monthly_fee' => 25, 'yearly_fee' => 250]]);

        $this->recordWhishPayment($token, $merchant)->assertCreated()
            ->assertJsonPath('merchant.cycle_locked_until', fn ($v) => $v !== null);

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['nextBillingCycle' => 'monthly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nextBillingCycle');
        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['billingCycle' => 'monthly'])
            ->assertStatus(422);
        $this->assertSame('yearly', $merchant->fresh()->billing_cycle);

        // Once the year is over the shop is free to choose again.
        $this->travel(13)->months();
        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['nextBillingCycle' => 'monthly'])->assertOk();
        $this->assertSame('monthly', $merchant->fresh()->billing_cycle);
    }

    public function test_a_shop_whose_subscription_stopped_can_choose_any_cycle_again(): void
    {
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['billing_cycle' => 'yearly', 'settings' => ['monthly_fee' => 25, 'yearly_fee' => 250]]);

        $this->recordWhishPayment($token, $merchant)->assertCreated();
        // The shop stopped (unpaid or ended): nothing paid is running any more.
        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['subscriptionStatus' => 'pending', 'status' => 'suspended'])->assertOk();

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['nextBillingCycle' => 'monthly'])
            ->assertOk()
            ->assertJsonPath('merchant.billing_cycle', 'monthly');
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
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'whatsapp_number' => '+96171112233', 'phone' => '+96171112233',
            'settings' => ['monthly_fee' => 25, 'yearly_fee' => 250],
        ]);

        $this->recordWhishPayment($token, $merchant, 'WS-TPL-1')->assertCreated();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '111222333/messages'));

        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], ['wa_templates' => ['neotalab_paid' => 'APPROVED']]);
        $platform->save();
        $this->recordWhishPayment($token, $merchant, 'WS-TPL-2')->assertCreated();
        Http::assertSent(fn ($r) => str_contains($r->url(), '111222333/messages') && $r['template']['name'] === 'neotalab_paid');
    }
}
