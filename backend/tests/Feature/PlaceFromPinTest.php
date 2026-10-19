<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Support\PlaceFromPin;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlaceFromPinTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['services.google_geocoding.key' => 'test-key']);
    }

    /** A Geocoding result for a point in Lebanon: governorate, district and locality. */
    protected function lebaneseResult(string $governorate, string $locality, string $district = 'Baabda District'): array
    {
        return ['status' => 'OK', 'results' => [[
            'address_components' => [
                ['long_name' => 'Lebanon', 'short_name' => 'LB', 'types' => ['country', 'political']],
                ['long_name' => $governorate, 'short_name' => $governorate, 'types' => ['administrative_area_level_1', 'political']],
                ['long_name' => $district, 'short_name' => $district, 'types' => ['administrative_area_level_2', 'political']],
                ['long_name' => $locality, 'short_name' => $locality, 'types' => ['locality', 'political']],
            ],
        ]]];
    }

    public function test_governorate_and_listed_city_are_read_from_the_pin(): void
    {
        $place = PlaceFromPin::fromResults($this->lebaneseResult('Mount Lebanon Governorate', 'Baabda')['results']);

        $this->assertSame(['region' => 'Mount Lebanon', 'city' => 'Baabda'], $place);
    }

    public function test_beirut_and_bekaa_names_map_to_the_app_regions(): void
    {
        $beirut = PlaceFromPin::fromResults($this->lebaneseResult('Beirut Governorate', 'Beirut', 'Beirut District')['results']);
        $bekaa = PlaceFromPin::fromResults($this->lebaneseResult('Beqaa Governorate', 'Zahle', 'Zahle District')['results']);

        $this->assertSame(['region' => 'Beirut', 'city' => 'Beirut'], $beirut);
        $this->assertSame(['region' => 'Bekaa', 'city' => 'Zahle'], $bekaa);
    }

    public function test_an_unlisted_town_is_kept_as_the_city_text(): void
    {
        $place = PlaceFromPin::fromResults($this->lebaneseResult('Mount Lebanon Governorate', 'Hazmieh', 'Metn District')['results']);

        $this->assertSame(['region' => 'Mount Lebanon', 'city' => 'Hazmieh'], $place);
    }

    public function test_a_point_outside_lebanon_gives_nothing(): void
    {
        $results = [['address_components' => [
            ['long_name' => 'Syria', 'short_name' => 'SY', 'types' => ['country', 'political']],
            ['long_name' => 'Damascus', 'short_name' => 'Damascus', 'types' => ['locality', 'political']],
        ]]];

        $this->assertSame(['region' => null, 'city' => null], PlaceFromPin::fromResults($results));
    }

    public function test_lookup_asks_google_and_reads_the_answer(): void
    {
        Http::fake(['maps.googleapis.com/*' => Http::response($this->lebaneseResult('North Governorate', 'Tripoli', 'Tripoli District'))]);

        $this->assertSame(['region' => 'North Lebanon', 'city' => 'Tripoli'], PlaceFromPin::lookup('34.4367,35.8497'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'latlng=34.4367%2C35.8497') && $request['key'] === 'test-key');
    }

    public function test_lookup_is_skipped_without_a_key_or_on_failure(): void
    {
        config(['services.google_geocoding.key' => '']);
        $this->assertNull(PlaceFromPin::lookup('33.8938,35.5018'));

        config(['services.google_geocoding.key' => 'test-key']);
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED'], 200)]);
        $this->assertNull(PlaceFromPin::lookup('33.8938,35.5018'));
    }

    public function test_admin_locate_returns_the_region_and_city_of_a_pin(): void
    {
        Http::fake(['maps.googleapis.com/*' => Http::response($this->lebaneseResult('Nabatieh Governorate', 'Marjeyoun', 'Marjeyoun District'))]);
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))
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
        Http::fake(['maps.googleapis.com/*' => Http::response($this->lebaneseResult('Mount Lebanon Governorate', 'Jounieh', 'Keserwan District'))]);

        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Pin Test Bakery',
            'businessType' => 'Bakery / Sweets',
            'country' => 'LB',
            'location' => '33.9808,35.6178',
            'contactName' => 'Test Owner',
            'whatsapp' => '+96170111222',
            'acceptedTerms' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('onboarding_requests', ['shop_name' => 'Pin Test Bakery', 'region' => 'Mount Lebanon', 'city' => 'Jounieh']);
    }

    public function test_shop_settings_locate_and_save_the_area(): void
    {
        Http::fake(['maps.googleapis.com/*' => Http::response($this->lebaneseResult('South Governorate', 'Sidon', 'Sidon District'))]);
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
