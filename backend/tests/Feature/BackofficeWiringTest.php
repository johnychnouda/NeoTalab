<?php

namespace Tests\Feature;

use App\Http\Resources\AdminMerchantResource;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\OnboardingRequest;
use App\Models\Order;
use App\Services\MerchantSettingsService;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class BackofficeWiringTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_merchant_profile_ops_whatsapp_syncs_to_merchant_row(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $merchant->forceFill(['whatsapp_number' => '+96170000101', 'phone' => '+96170000101'])->save();

        $this->withToken($this->tokenFor($owner))
            ->patchJson('/api/v1/settings/profile', ['opsWhatsapp' => '+96170161650'])
            ->assertOk();

        $merchant->refresh();
        $this->assertSame('+96170161650', $merchant->settings['ops_whatsapp']);
        $this->assertSame('+96170161650', $merchant->whatsapp_number);
        $this->assertSame('+96170161650', $merchant->phone);
    }

    public function test_merchant_profile_ops_whatsapp_rejects_duplicate_number(): void
    {
        $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'whatsapp_number' => '+96170161650',
        ]);
        [, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'whatsapp_number' => '+96170000101',
        ]);

        $this->withToken($this->tokenFor($owner))
            ->patchJson('/api/v1/settings/profile', ['opsWhatsapp' => '+96170161650'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ops_whatsapp']);
    }

    public function test_admin_merchant_list_uses_ops_whatsapp_when_set(): void
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'whatsapp_number' => '+96170000101',
            'settings' => ['ops_whatsapp' => '+96170161650', 'subscription_status' => 'trial'],
        ]);
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/merchants')
            ->assertOk()
            ->assertJsonPath('data.0.id', $merchant->id)
            ->assertJsonPath('data.0.whatsapp_number', '+96170161650');
    }

    public function test_admin_update_whatsapp_syncs_to_merchant_profile(): void
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'whatsapp_number' => '+96170000101',
            'settings' => ['ops_whatsapp' => '+96170000101', 'subscription_status' => 'trial'],
        ]);
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
                'whatsappNumber' => '+96170999999',
            ])
            ->assertOk()
            ->assertJsonPath('merchant.whatsapp_number', '+96170999999');

        $merchant->refresh();
        $this->assertSame('+96170999999', $merchant->whatsapp_number);
        $this->assertSame('+96170999999', $merchant->settings['ops_whatsapp']);

        $profile = app(MerchantSettingsService::class)->profile($merchant);
        $this->assertSame('+96170999999', $profile['ops_whatsapp']);
    }

    public function test_admin_update_shop_name_syncs_to_merchant_profile(): void
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Old Name',
            'settings' => ['subscription_status' => 'trial'],
        ]);
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
                'shopName' => 'New Shop Name',
            ])
            ->assertOk()
            ->assertJsonPath('merchant.shop_name', 'New Shop Name');

        $merchant->refresh();
        $this->assertSame('New Shop Name', $merchant->name);

        $profile = app(MerchantSettingsService::class)->profile($merchant);
        $this->assertSame('New Shop Name', $profile['shop_name']);
    }

    public function test_merchant_profile_shop_name_syncs_to_owner_portal(): void
    {
        [$merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'name' => 'Old Name',
            'settings' => ['subscription_status' => 'trial'],
        ]);

        $this->withToken($this->tokenFor($owner))
            ->patchJson('/api/v1/settings/profile', ['shopName' => 'Updated From Backoffice'])
            ->assertOk();

        $merchant->refresh();
        $this->assertSame('Updated From Backoffice', $merchant->name);

        $ownerView = (new AdminMerchantResource($merchant))->resolve();
        $this->assertSame('Updated From Backoffice', $ownerView['shop_name']);
    }

    public function test_merchant_can_load_settings_orders_customers_drivers_analytics(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $customer = Customer::factory()->forMerchant($merchant)->create();
        Order::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'status' => 'pending_payment',
            'subtotal' => 10,
            'delivery_fee' => 2,
            'total' => 12,
            'payment_method' => 'cash',
        ]);

        $token = $this->tokenFor($owner);

        $this->withToken($token)->getJson('/api/v1/settings/profile')->assertOk()->assertJsonStructure(['profile']);
        $this->withToken($token)->getJson('/api/v1/settings/hours')->assertOk()->assertJsonStructure(['hours']);
        $this->withToken($token)->getJson('/api/v1/settings/zones')->assertOk()->assertJsonStructure(['zones']);
        $this->withToken($token)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(1, 'customers');
        $this->withToken($token)->getJson('/api/v1/drivers')->assertOk();
        $this->withToken($token)->getJson('/api/v1/analytics/dashboard?days=30')->assertOk()->assertJsonStructure(['summary']);
    }

    public function test_a_shop_can_edit_its_delivery_area_but_not_another_shops(): void
    {
        [, $owner] = $this->merchantWithUser();
        [, $other] = $this->merchantWithUser();
        $token = $this->tokenFor($owner);
        $otherToken = $this->tokenFor($other);

        $id = $this->withToken($token)->postJson('/api/v1/settings/zones', ['name' => 'Jbeil', 'deliveryFee' => 1.5])->json('zone.id');

        $this->withToken($token)
            ->patchJson("/api/v1/settings/zones/{$id}", ['name' => 'Jbeil centre', 'deliveryFee' => 2, 'minimumOrder' => 5, 'deliveryMins' => 20])
            ->assertOk()->assertJsonPath('zone.name', 'Jbeil centre');
        $this->assertDatabaseHas('delivery_zones', ['id' => $id, 'delivery_fee' => 2, 'minimum_order' => 5, 'delivery_mins' => 20]);

        // The next request is the other shop (each real request starts with a fresh app).
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance(CurrentTenant::class);
        $this->withToken($otherToken)
            ->patchJson("/api/v1/settings/zones/{$id}", ['name' => 'Taken'])
            ->assertNotFound();
    }

    public function test_team_numbers_are_saved_in_whatsapp_form_and_members_can_come_back(): void
    {
        [, $owner] = $this->merchantWithUser();
        $token = $this->tokenFor($owner);

        $id = $this->withToken($token)->postJson('/api/v1/drivers', ['name' => 'Rami', 'phone' => '70 123 456', 'role' => 'staff'])
            ->assertCreated()->json('driver.id');
        $this->assertDatabaseHas('drivers', ['id' => $id, 'phone' => '+96170123456', 'whatsapp_number' => '+96170123456']);

        $this->withToken($token)->postJson('/api/v1/drivers', ['name' => 'Again', 'phone' => '+961 70 123 456'])->assertUnprocessable();

        $this->withToken($token)->patchJson("/api/v1/drivers/{$id}", ['name' => 'Rami K.', 'phone' => '03 123 456', 'role' => 'driver'])->assertOk();
        $this->assertDatabaseHas('drivers', ['id' => $id, 'name' => 'Rami K.', 'phone' => '+9613123456', 'role' => 'driver']);

        $this->withToken($token)->deleteJson("/api/v1/drivers/{$id}")->assertOk();
        $this->withToken($token)->postJson('/api/v1/drivers', ['name' => 'Rami', 'phone' => '03123456', 'role' => 'staff'])->assertCreated();
        $this->assertDatabaseHas('drivers', ['id' => $id, 'is_active' => true, 'role' => 'staff']);
    }

    public function test_admin_onboarding_flow(): void
    {
        $admin = $this->platformAdmin();
        $request = OnboardingRequest::create([
            'shop_name' => 'New Shop',
            'whatsapp' => '+96170001122',
            'status' => 'pending',
        ]);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/onboarding')
            ->assertOk()
            ->assertJsonCount(1, 'requests');

        $this->withToken($this->tokenFor($admin))
            ->postJson("/api/v1/admin/onboarding/{$request->id}/approve", [
                'password' => '123456',
                'subscriptionStatus' => 'trial',
            ])
            ->assertOk()
            ->assertJsonStructure(['merchant']);

        $this->assertDatabaseHas('merchants', ['name' => 'New Shop']);
    }

    public function test_public_onboarding_apply(): void
    {
        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Join Shop',
            'contactName' => 'Ahmad',
            'businessType' => 'Restaurant / Café',
            'whatsapp' => '+96178887777',
            'city' => 'Beirut',
            'region' => 'Beirut',
            'street' => 'Hamra Street',
            'country' => 'LB',
            'acceptedTerms' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('onboarding_requests', ['shop_name' => 'Join Shop', 'status' => 'pending']);
    }

    public function test_an_approved_shop_gets_lebanon_time_and_dollar_prices(): void
    {
        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Jbeil Bites', 'contactName' => 'Omar', 'businessType' => 'Restaurant / Café',
            'whatsapp' => '+96170123456', 'country' => 'lb', 'region' => 'Mount Lebanon', 'city' => 'Jbeil', 'street' => 'Main Road',
            'acceptedTerms' => true,
        ])->assertCreated();

        $request = OnboardingRequest::query()->where('shop_name', 'Jbeil Bites')->sole();
        $this->assertSame('LB', $request->country);

        $this->withToken($this->tokenFor($this->platformAdmin()))
            ->postJson("/api/v1/admin/onboarding/{$request->id}/approve", ['password' => '123456', 'subscriptionStatus' => 'trial'])
            ->assertOk();

        $this->assertDatabaseHas('merchants', ['name' => 'Jbeil Bites', 'timezone' => 'Asia/Beirut', 'currency' => 'USD']);
        $this->assertSame(89500, (int) Merchant::query()->where('name', 'Jbeil Bites')->sole()->settings['lbp_rate']);
    }

    public function test_only_lebanese_shops_can_apply_for_now(): void
    {
        $base = [
            'shopName' => 'Join Shop', 'contactName' => 'Ahmad', 'businessType' => 'Restaurant / Café',
            'city' => 'Dubai', 'region' => 'Dubai', 'street' => 'Main St', 'acceptedTerms' => true,
        ];

        $this->postJson('/api/v1/onboarding/apply', $base + ['country' => 'AE', 'whatsapp' => '+971501234567'])
            ->assertUnprocessable()->assertJsonValidationErrorFor('country');
        $this->postJson('/api/v1/onboarding/apply', $base + ['country' => 'LB', 'whatsapp' => '+971501234567'])
            ->assertUnprocessable()->assertJsonValidationErrorFor('whatsapp');
    }

    public function test_public_onboarding_rejects_invalid_whatsapp(): void
    {
        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Join Shop',
            'contactName' => 'Ahmad',
            'businessType' => 'Restaurant / Café',
            'whatsapp' => '96178887777',
            'city' => 'Beirut',
            'region' => 'Beirut',
            'street' => 'Hamra Street',
            'country' => 'LB',
            'acceptedTerms' => true,
        ])->assertUnprocessable();
    }

    public function test_public_onboarding_rejects_without_terms(): void
    {
        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Join Shop',
            'contactName' => 'Ahmad',
            'businessType' => 'Restaurant / Café',
            'whatsapp' => '+96178887777',
            'city' => 'Beirut',
            'region' => 'Beirut',
            'street' => 'Hamra Street',
            'country' => 'LB',
        ])->assertUnprocessable();
    }

    public function test_public_onboarding_rejects_duplicate_pending_whatsapp(): void
    {
        OnboardingRequest::create([
            'shop_name' => 'Existing',
            'whatsapp' => '+96179998888',
            'status' => 'pending',
        ]);

        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Another Shop',
            'contactName' => 'Sara',
            'businessType' => 'Bakery / Sweets',
            'whatsapp' => '+96179998888',
            'city' => 'Tripoli',
            'region' => 'North Lebanon',
            'street' => 'Mina Road',
            'country' => 'LB',
            'acceptedTerms' => true,
        ])->assertUnprocessable();
    }
}
