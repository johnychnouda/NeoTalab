<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Support\PlaceFromPin;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlaceFromPinTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_pin_in_a_listed_town_gives_its_governorate_and_city(): void
    {
        $this->assertSame(['region' => 'South Lebanon', 'city' => 'Sidon'], PlaceFromPin::lookup('33.5630,35.3690'));
        $this->assertSame(['region' => 'Beirut', 'city' => 'Beirut'], PlaceFromPin::lookup('33.8938,35.5018'));
        $this->assertSame(['region' => 'Bekaa', 'city' => 'Zahle'], PlaceFromPin::lookup('33.8463,35.9019'));
    }

    public function test_a_pin_near_a_town_but_outside_it_gives_only_the_governorate(): void
    {
        // Between Zgharta and Bsharri: about 18 km from Bsharri, so the governorate is known but no town.
        $this->assertSame(['region' => 'North Lebanon', 'city' => null], PlaceFromPin::lookup('34.1000,35.9000'));
    }

    public function test_a_pin_far_from_every_listed_town_gives_nothing(): void
    {
        // Damascus, outside Lebanon.
        $this->assertNull(PlaceFromPin::lookup('33.5138,36.2765'));
    }

    public function test_a_text_that_is_not_a_pin_gives_nothing(): void
    {
        $this->assertNull(PlaceFromPin::lookup('hello'));
    }

    public function test_admin_locate_returns_the_region_and_city_of_a_pin(): void
    {
        $this->withToken($this->tokenFor($this->platformAdmin()))
            ->postJson('/api/v1/admin/merchants/locate', ['location' => '33.3600,35.5900'])
            ->assertOk()
            ->assertJsonPath('location', '33.36,35.59')
            ->assertJsonPath('region', 'Nabatieh')
            ->assertJsonPath('city', 'Marjeyoun');
    }

    public function test_admin_locate_refuses_text_with_no_location(): void
    {
        $this->withToken($this->tokenFor($this->platformAdmin()))
            ->postJson('/api/v1/admin/merchants/locate', ['location' => 'hello'])
            ->assertStatus(422);
    }

    public function test_saving_a_pasted_pin_stores_it_and_shows_the_map_link(): void
    {
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER);

        $this->withToken($this->tokenFor($this->platformAdmin()))
            ->patchJson("/api/v1/admin/merchants/{$merchant->id}", [
                'location' => 'https://www.google.com/maps?q=33.8938,35.5018',
                'region' => 'Beirut',
                'city' => 'Beirut',
            ])
            ->assertOk()
            ->assertJsonPath('merchant.shop_location', '33.8938,35.5018')
            ->assertJsonPath('merchant.region', 'Beirut');

        $this->assertSame('33.8938,35.5018', Merchant::find($merchant->id)->settings['shop_location']);
    }

    public function test_a_join_request_gets_its_governorate_and_city_from_the_pin(): void
    {
        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Pin Test Bakery',
            'businessType' => 'Bakery / Sweets',
            'country' => 'LB',
            'location' => '33.5630,35.3690',
            'contactName' => 'Test Owner',
            'whatsapp' => '+96170111222',
            'acceptedTerms' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('onboarding_requests', ['shop_name' => 'Pin Test Bakery', 'region' => 'South Lebanon', 'city' => 'Sidon']);
    }

    public function test_shop_settings_locate_and_save_the_area(): void
    {
        [, $owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER);

        $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/settings/locate', ['location' => '33.5630,35.3690'])
            ->assertOk()
            ->assertJsonPath('region', 'South Lebanon')
            ->assertJsonPath('city', 'Sidon');

        $this->withToken($this->tokenFor($owner))
            ->patchJson('/api/v1/settings/profile', ['region' => 'South Lebanon', 'city' => 'Sidon', 'shop_location' => '33.5630,35.3690'])
            ->assertOk();

        $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/settings/profile')
            ->assertOk()
            ->assertJsonPath('profile.region', 'South Lebanon')
            ->assertJsonPath('profile.city', 'Sidon');
    }
}
