<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AdminMerchantManagementTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_update_merchant_billing(): void
    {
        [$merchant] = $this->merchantWithUser();
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
                'billingCycle' => 'yearly',
                'monthlyFee' => 25,
                'yearlyFee' => 250,
            ])
            ->assertOk()
            ->assertJsonPath('merchant.billing_cycle', 'yearly')
            ->assertJsonPath('merchant.monthly_fee', 25)
            ->assertJsonPath('merchant.yearly_fee', 250);
    }

    public function test_admin_can_mark_merchant_paid_and_list_payments(): void
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['settings' => ['monthly_fee' => 30, 'yearly_fee' => 300]]);
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
                'subscriptionStatus' => 'paid',
                'lastPaymentAt' => '2026-07-05',
            ])
            ->assertOk()
            ->assertJsonPath('merchant.subscription_status', 'paid');

        $this->withToken($this->tokenFor($admin))
            ->getJson("/api/v1/admin/merchants/{$merchant->id}/payments")
            ->assertOk()
            ->assertJsonCount(1, 'payments');
    }

    public function test_admin_cannot_change_fee_during_active_paid_period(): void
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'billing_cycle' => 'monthly',
            'settings' => [
                'monthly_fee' => 50,
                'yearly_fee' => 500,
                'subscription_status' => 'paid',
                'last_payment_at' => now()->toDateString(),
            ],
        ]);
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
                'monthlyFee' => 80,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['monthlyFee']);

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
                'billingCycle' => 'yearly',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['billingCycle']);

        // Alternate-cycle fee can still be planned ahead.
        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
                'yearlyFee' => 600,
            ])
            ->assertOk()
            ->assertJsonPath('merchant.yearly_fee', 600);
    }

    public function test_admin_can_switch_cycle_mid_period_with_new_payment(): void
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'billing_cycle' => 'monthly',
            'settings' => [
                'monthly_fee' => 50,
                'yearly_fee' => 290,
                'subscription_status' => 'paid',
                'last_payment_at' => now()->toDateString(),
            ],
        ]);
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
                'billingCycle' => 'yearly',
                'yearlyFee' => 290,
                'subscriptionStatus' => 'paid',
                'lastPaymentAt' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('merchant.billing_cycle', 'yearly')
            ->assertJsonPath('merchant.subscription_status', 'paid');

        $this->withToken($this->tokenFor($admin))
            ->getJson("/api/v1/admin/merchants/{$merchant->id}/payments")
            ->assertOk()
            ->assertJsonPath('payments.0.cycle', 'yearly')
            ->assertJsonPath('payments.0.amount', 290);
    }

    public function test_admin_can_save_and_test_bot_credentials(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'display_phone_number' => '+96171112233',
                'verified_name' => 'Test',
            ], 200),
        ]);

        [$merchant] = $this->merchantWithUser();
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}/bot", [
                'phoneNumberId' => '123456789',
                'accessToken' => 'EAAX_test_token',
                'tokenExpires' => now()->addDays(30)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('merchant.bot_status', 'active');

        $this->withToken($this->tokenFor($admin))
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/bot/test")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_admin_can_impersonate_merchant_owner(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/impersonate")
            ->assertOk()
            ->assertJsonStructure(['token', 'merchant']);

        $this->assertTrue($owner->hasRole(Roles::MERCHANT_OWNER));
    }

    public function test_admin_can_create_merchant_from_owner_portal_payload(): void
    {
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->postJson('/api/v1/admin/merchants', [
                'shopName' => 'Joe Snacks',
                'whatsappNumber' => '+96171112233',
                'password' => '123456',
                'businessType' => 'retail',
                'subscriptionStatus' => 'trial',
            ])
            ->assertCreated()
            ->assertJsonPath('data.shop_name', 'Joe Snacks')
            ->assertJsonPath('data.monthly_fee', 29)
            ->assertJsonPath('data.yearly_fee', 290);

        $this->assertDatabaseHas('merchants', ['name' => 'Joe Snacks', 'whatsapp_number' => '+96171112233']);
    }

    public function test_admin_can_broadcast(): void
    {
        $this->merchantWithUser(Roles::MERCHANT_OWNER, ['status' => 'active', 'settings' => ['subscription_status' => 'paid']]);
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->postJson('/api/v1/admin/broadcast', ['message' => 'Maintenance tonight'])
            ->assertOk()
            ->assertJsonStructure(['sent', 'message']);
    }

    public function test_admin_can_delete_merchant(): void
    {
        [$merchant] = $this->merchantWithUser();
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->deleteJson("/api/v1/admin/merchants/{$merchant->id}")
            ->assertOk();

        $this->assertDatabaseMissing('merchants', ['id' => $merchant->id]);
    }
}
