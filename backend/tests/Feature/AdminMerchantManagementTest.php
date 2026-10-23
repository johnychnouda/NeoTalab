<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\OnboardingRequest;
use App\Models\Product;
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
        $expires = $owner->tokens()->where('name', 'impersonation')->first()->expires_at;
        $this->assertTrue($expires->between(now()->addHours(7), now()->addHours(9)));
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
            ->assertJsonPath('data.monthly_fee', 30)
            ->assertJsonPath('data.yearly_fee', 300);

        $this->assertDatabaseHas('merchants', ['name' => 'Joe Snacks', 'whatsapp_number' => '+96171112233']);
    }

    public function test_business_type_defaults_survive_owner_portal_and_join_approval(): void
    {
        $token = $this->tokenFor($this->platformAdmin());

        $this->withToken($token)->postJson('/api/v1/admin/merchants', [
            'shopName' => 'Rose Salon', 'whatsappNumber' => '+96171112234', 'password' => '123456', 'businessType' => 'Salon / Barber / Beauty',
        ])->assertCreated();
        $salon = Merchant::query()->where('name', 'Rose Salon')->sole();
        $this->assertSame('appointments', $salon->settings['business_mode'], 'a salon takes bookings from day one');
        $this->assertSame('services', $salon->settings['business_kind']);
        $this->assertSame('auto', $salon->settings['mode'], 'owner-portal settings are kept too');

        $request = OnboardingRequest::query()->forceCreate([
            'shop_name' => 'Cedar Phones', 'contact_name' => 'Joe', 'whatsapp' => '+96171112235',
            'business_type' => 'Electronics / Phones', 'country' => 'LB', 'city' => 'Beirut', 'street' => 'Hamra', 'status' => 'pending',
        ]);
        $this->withToken($token)->postJson("/api/v1/admin/onboarding/{$request->id}/approve", ['password' => 'secret-123'])->assertSuccessful();
        $shop = Merchant::query()->where('name', 'Cedar Phones')->sole();
        $this->assertSame('days', $shop->settings['timing_unit'], 'retail counts delivery in days');
        $this->assertSame('orders', $shop->settings['business_mode']);
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

    public function test_existing_shops_get_their_missing_business_settings_once(): void
    {
        $salon = Merchant::factory()->create(['business_type' => 'Salon / Barber / Beauty', 'settings' => ['mode' => 'auto']]);
        $shop = Merchant::factory()->create(['business_type' => 'Electronics / Phones', 'settings' => ['timing_unit' => 'hours']]);

        (require database_path('migrations/2026_10_23_000000_fill_missing_business_kind_settings.php'))->up();

        $this->assertSame('appointments', $salon->fresh()->settings['business_mode']);
        $this->assertSame('auto', $salon->fresh()->settings['mode'], 'other settings are kept');
        $this->assertSame('hours', $shop->fresh()->settings['timing_unit'], "a shop's own choice is kept");
        $this->assertSame('orders', $shop->fresh()->settings['business_mode']);
    }

    public function test_deleting_a_shop_removes_all_its_data_and_nothing_else(): void
    {
        [$gone, $owner] = $this->merchantWithUser();
        [$kept] = $this->merchantWithUser();
        $this->tokenFor($owner);
        foreach ([$gone, $kept] as $m) {
            Product::factory()->forMerchant($m)->create(['name' => 'Falafel']);
            Customer::query()->withoutGlobalScopes()->forceCreate(['merchant_id' => $m->id, 'phone' => '+96170000001']);
        }

        $this->withToken($this->tokenFor($this->platformAdmin()))->deleteJson("/api/v1/admin/merchants/{$gone->id}")->assertOk();

        foreach (['users', 'products', 'customers'] as $table) {
            $this->assertDatabaseMissing($table, ['merchant_id' => $gone->id]);
            $this->assertDatabaseHas($table, ['merchant_id' => $kept->id]);
        }
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $owner->id]);
    }
}
