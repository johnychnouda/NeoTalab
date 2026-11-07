<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Shops pay NeoTalab by Whish transfer. The platform owner checks the NeoTalab Whish account and
 * records the payment with its Whish reference. The app never marks a shop paid by itself, and
 * the same reference can only be recorded once.
 */
class SubscriptionPaymentTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        $this->admin = $this->platformAdmin();
    }

    private function shop(array $settings = [], string $cycle = 'monthly'): array
    {
        $defaults = ['subscription_status' => 'trial', 'monthly_fee' => 25, 'yearly_fee' => 250];

        return $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'billing_cycle' => $cycle,
            'settings' => array_merge($defaults, $settings),
        ]);
    }

    private function record(Merchant $merchant, string $reference, ?float $received = null)
    {
        return $this->withToken($this->tokenFor($this->admin))
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/payments", [
                'whish_reference' => $reference,
                'sender_name' => 'ZENAR MAHMOUD',
                'sender_phone' => '96176459317',
                'received_amount' => $received ?? (float) ($merchant->settings[$merchant->billing_cycle === 'yearly' ? 'yearly_fee' : 'monthly_fee'] ?? 0),
            ]);
    }

    public function test_recording_a_whish_payment_marks_the_shop_paid_for_one_month(): void
    {
        [$merchant] = $this->shop();

        $this->record($merchant, 'WS-88213')->assertCreated();

        $merchant->refresh();
        $this->assertSame('paid', $merchant->settings['subscription_status']);
        $this->assertTrue($merchant->subscription_ends_at->equalTo(Carbon::now()->addMonth()));

        $payment = SubscriptionPayment::query()->sole();
        $this->assertSame('WS-88213', $payment->whish_reference);
        $this->assertSame('25.00', (string) $payment->amount);
        $this->assertSame('monthly', $payment->cycle);
        $this->assertSame($this->admin->id, $payment->confirmed_by);
        $this->assertNotNull($payment->confirmed_at);
    }

    public function test_a_yearly_shop_pays_the_yearly_fee_for_a_year(): void
    {
        [$merchant] = $this->shop([], 'yearly');

        $this->record($merchant, 'WS-YEAR-1')->assertCreated();

        $merchant->refresh();
        $this->assertTrue($merchant->subscription_ends_at->equalTo(Carbon::now()->addYear()));
        $this->assertSame('250.00', (string) SubscriptionPayment::query()->sole()->amount);
    }

    public function test_the_same_reference_is_accepted_only_once_even_at_another_shop(): void
    {
        [$first] = $this->shop();
        [$second] = $this->shop();
        $this->record($first, 'WS-DUP-1')->assertCreated();

        $this->record($second, 'WS-DUP-1')->assertUnprocessable();

        $this->assertSame(1, SubscriptionPayment::query()->count());
        $this->assertNotSame('paid', ($second->fresh()->settings ?? [])['subscription_status'] ?? null);
    }

    public function test_a_shop_without_a_fee_cannot_be_recorded(): void
    {
        [$merchant] = $this->shop(['monthly_fee' => 0]);

        $this->record($merchant, 'WS-NOFEE-1')->assertUnprocessable();

        $this->assertSame(0, SubscriptionPayment::query()->count());
    }

    public function test_a_bad_reference_is_refused(): void
    {
        [$merchant] = $this->shop();

        $this->record($merchant, 'x')->assertUnprocessable();
        $this->record($merchant, 'WS 88 213')->assertUnprocessable();
        $this->record($merchant, '')->assertUnprocessable();

        $this->assertSame(0, SubscriptionPayment::query()->count());
    }

    public function test_paying_again_adds_the_month_to_the_current_end(): void
    {
        [$merchant] = $this->shop(['subscription_status' => 'paid']);
        $merchant->forceFill(['subscription_ends_at' => Carbon::now()->addDays(10)])->save();

        $this->record($merchant, 'WS-RENEW-1')->assertCreated();

        $this->assertTrue($merchant->fresh()->subscription_ends_at->equalTo(Carbon::now()->addDays(10)->addMonth()));
    }

    public function test_editing_a_shop_cannot_mark_it_paid(): void
    {
        [$merchant] = $this->shop();

        $this->withToken($this->tokenFor($this->admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['lastPaymentAt' => '2026-10-29T08:00:00Z'])
            ->assertUnprocessable();
        $this->withToken($this->tokenFor($this->admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['subscriptionStatus' => 'paid'])
            ->assertUnprocessable();

        $this->assertSame('trial', $merchant->fresh()->settings['subscription_status']);
        $this->assertSame(0, SubscriptionPayment::query()->count());
    }

    public function test_only_the_platform_owner_can_record_a_payment(): void
    {
        [$merchant, $owner] = $this->shop();

        $this->withToken($this->tokenFor($owner))
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/payments", ['whish_reference' => 'WS-SHOP-1'])
            ->assertForbidden();

        $this->assertSame(0, SubscriptionPayment::query()->count());
    }

    public function test_the_payments_list_shows_reference_amount_and_who_confirmed(): void
    {
        [$merchant] = $this->shop();
        $this->record($merchant, 'WS-LIST-1')->assertCreated();

        $res = $this->withToken($this->tokenFor($this->admin))
            ->getJson("/api/v1/admin/merchants/{$merchant->id}/payments")
            ->assertOk();

        $res->assertJsonPath('payments.0.whish_reference', 'WS-LIST-1')
            ->assertJsonPath('payments.0.amount', '25.00')
            ->assertJsonPath('payments.0.confirmed_by', $this->admin->id);
    }

    public function test_the_transfer_must_match_the_fee_and_nothing_is_saved_otherwise(): void
    {
        [$merchant] = $this->shop();

        $this->record($merchant, 'WS-SHORT-1', 20)->assertUnprocessable()->assertJsonValidationErrors('received_amount');
        $this->record($merchant, 'WS-OVER-1', 30)->assertUnprocessable()->assertJsonValidationErrors('received_amount');

        $this->assertSame('trial', $merchant->fresh()->settings['subscription_status']);
        $this->assertSame(0, SubscriptionPayment::query()->count());

        $this->record($merchant, 'WS-SHORT-1', 25)->assertCreated();
    }

    public function test_the_sender_is_saved_and_listed(): void
    {
        [$merchant] = $this->shop();
        $this->record($merchant, 'WS-SENDER-1')->assertCreated();

        $this->withToken($this->tokenFor($this->admin))
            ->getJson("/api/v1/admin/merchants/{$merchant->id}/payments")
            ->assertJsonPath('payments.0.sender_name', 'ZENAR MAHMOUD')
            ->assertJsonPath('payments.0.sender_phone', '96176459317');
    }

    public function test_the_sender_and_the_amount_are_required(): void
    {
        [$merchant] = $this->shop();

        $this->withToken($this->tokenFor($this->admin))
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/payments", ['whish_reference' => 'WS-BARE-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sender_name', 'sender_phone', 'received_amount']);
    }

    public function test_paying_switches_on_a_shop_that_was_stopped_for_not_paying(): void
    {
        [$merchant] = $this->shop(['subscription_status' => 'pending']);
        $merchant->forceFill(['status' => 'suspended'])->save();

        $this->record($merchant, 'WS-BACKON-1')->assertCreated();

        $this->assertSame('active', $merchant->fresh()->status);
        $this->assertSame('paid', $merchant->fresh()->settings['subscription_status']);
    }

    public function test_paying_does_not_switch_on_a_shop_the_owner_stopped_by_hand(): void
    {
        [$merchant] = $this->shop(['subscription_status' => 'paid']);
        $merchant->forceFill(['status' => 'suspended'])->save();

        $this->record($merchant, 'WS-HELD-1')->assertCreated();

        $this->assertSame('suspended', $merchant->fresh()->status);
    }
}
