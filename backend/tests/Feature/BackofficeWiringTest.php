<?php

namespace Tests\Feature;

use App\Http\Resources\AdminMerchantResource;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\OnboardingRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\MerchantSettingsService;
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
        $merchant->forceFill(['whatsapp_number' => '+96170161659', 'phone' => '+96170161659'])->save();

        $this->withToken($this->tokenFor($owner))
            ->patchJson('/api/v1/settings/profile', ['opsWhatsapp' => '+96170161650'])
            ->assertOk();

        $merchant->refresh();
        $this->assertSame('+96170161650', $merchant->settings['ops_whatsapp']);
        $this->assertSame('+96170161650', $merchant->whatsapp_number);
        $this->assertSame('+96170161650', $merchant->phone);
    }

    public function test_admin_merchant_list_uses_ops_whatsapp_when_set(): void
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'whatsapp_number' => '+96170161659',
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
            'whatsapp_number' => '+96170161659',
            'settings' => ['ops_whatsapp' => '+96170161659', 'subscription_status' => 'trial'],
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
        $this->withToken($token)->getJson('/api/v1/orders/live')->assertOk()->assertJsonCount(1, 'orders');
        $this->withToken($token)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(1, 'customers');
        $this->withToken($token)->getJson('/api/v1/drivers')->assertOk();
        $this->withToken($token)->getJson('/api/v1/analytics/dashboard?days=30')->assertOk()->assertJsonStructure(['summary']);
    }

    public function test_merchant_can_accept_order(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $customer = Customer::factory()->forMerchant($merchant)->create();
        $order = Order::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'status' => 'pending_payment',
            'subtotal' => 10,
            'delivery_fee' => 2,
            'total' => 12,
            'payment_method' => 'cash',
        ]);
        OrderItem::create([
            'merchant_id' => $merchant->id,
            'order_id' => $order->id,
            'product_name' => 'Test Item',
            'quantity' => 1,
            'unit_price' => 10,
            'item_total' => 10,
        ]);

        $this->withToken($this->tokenFor($owner))
            ->postJson("/api/v1/orders/{$order->id}/accept")
            ->assertOk()
            ->assertJsonPath('order.status', 'confirmed');
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
