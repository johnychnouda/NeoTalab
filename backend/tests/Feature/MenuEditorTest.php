<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Product;
use App\Services\TenantProvisioningService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class MenuEditorTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Merchant $merchant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        [$this->merchant, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['business_type' => 'Restaurant / Café']);
        $this->token = $this->tokenFor($owner);
    }

    public function test_item_with_one_name_unit_offer_sizes_and_options_is_saved_in_one_go(): void
    {
        $id = $this->withToken($this->token)->postJson('/api/v1/products', [
            'name' => 'بيتزا Margherita', 'price' => 10, 'unit' => null,
            'offer_percent' => 20, 'offer_starts_on' => now()->toDateString(), 'offer_ends_on' => now()->addWeek()->toDateString(),
        ])->assertCreated()->json('data.id');

        $payload = ['variants' => [['name' => 'Small', 'price' => 8], ['name' => 'Large', 'price' => 12]],
            'groups' => [['name' => 'Extras', 'required' => false, 'max_select' => 3, 'modifiers' => [['name' => 'Olives', 'price_delta' => 0.5], ['name' => 'Mushrooms']]]]];
        $res = $this->withToken($this->token)->putJson("/api/v1/products/{$id}/options", $payload)->assertOk();

        $res->assertJsonPath('data.name', 'بيتزا Margherita')->assertJsonPath('data.offer_active', true)
            ->assertJsonCount(2, 'data.variants')->assertJsonPath('data.modifier_groups.0.modifiers.1.name', 'Mushrooms');

        // Saving again keeps the ids of rows that are still there (carts keep working).
        $large = collect($res->json('data.variants'))->firstWhere('name', 'Large')['id'];
        $again = $this->withToken($this->token)->putJson("/api/v1/products/{$id}/options", [
            'variants' => [['id' => $large, 'name' => 'Large', 'price' => 13]], 'groups' => [],
        ])->assertOk();
        $this->assertSame($large, $again->json('data.variants.0.id'));
        $this->assertEquals(13, $again->json('data.variants.0.price'));
        $this->assertSame([], $again->json('data.modifier_groups'));
    }

    public function test_options_of_another_shop_cannot_be_edited(): void
    {
        [$other] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        $foreign = Product::factory()->forMerchant($other)->create();

        $this->withToken($this->token)->putJson("/api/v1/products/{$foreign->id}/options", ['variants' => [], 'groups' => []])->assertNotFound();
    }

    public function test_business_type_sets_menu_wording_and_defaults(): void
    {
        $shop = app(TenantProvisioningService::class)->provision(
            ['name' => 'Zara Jbeil', 'business_type' => 'Clothing / Shoes'],
            ['name' => 'Owner', 'email' => 'zara@example.com', 'password' => 'secret-password'],
        )['merchant'];

        $this->assertSame('retail', $shop->settings['business_kind']);
        $this->assertSame('days', $shop->settings['timing_unit'], 'clothes are delivered in days');

        $profile = $this->withToken($this->token)->getJson('/api/v1/settings/profile')->assertOk();
        $profile->assertJsonPath('profile.business_kind', 'restaurant')->assertJsonPath('profile.menu_profile.word', 'menu');

        // Switching to a salon: bookings, and the menu becomes "Services".
        $this->withToken($this->token)->patchJson('/api/v1/settings/profile', ['business_kind' => 'services', 'timing_unit' => 'minutes'])->assertOk();
        $settings = $this->merchant->fresh()->settings;
        $this->assertSame('appointments', $settings['business_mode']);
        $this->withToken($this->token)->getJson('/api/v1/settings/profile')->assertJsonPath('profile.menu_profile.word', 'services');
    }

    public function test_setup_checklist_shows_what_is_left(): void
    {
        $setup = $this->withToken($this->token)->getJson('/api/v1/settings/setup')->assertOk()->json('setup');
        $this->assertFalse($setup['ready']);
        $this->assertSame(['menu', 'hours', 'zones', 'team', 'whish', 'report'], array_column($setup['steps'], 'key'));
        $this->assertFalse($setup['whatsapp_connected']);

        Product::factory()->forMerchant($this->merchant)->create();
        $menu = collect($this->withToken($this->token)->getJson('/api/v1/settings/setup')->json('setup.steps'))->firstWhere('key', 'menu');
        $this->assertTrue($menu['done']);
    }

    public function test_the_shop_sees_its_trial_and_when_it_ends(): void
    {
        $this->merchant->forceFill(['trial_ends_at' => now()->addDays(2), 'settings' => array_merge($this->merchant->settings ?? [], [
            'subscription_status' => 'trial', 'monthly_fee' => 30,
        ])])->save();

        $sub = $this->withToken($this->token)->getJson('/api/v1/settings/profile')->assertOk()->json('profile.subscription');

        $this->assertSame('trial', $sub['status']);
        $this->assertSame('monthly', $sub['billing_cycle']);
        $this->assertEquals(30, $sub['fee']);
        $this->assertSame($this->merchant->fresh()->trial_ends_at->toIso8601String(), $sub['renews_at']);
    }
}
