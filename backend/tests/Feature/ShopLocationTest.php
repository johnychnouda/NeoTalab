<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\OnboardingRequest;
use App\Models\Order;
use App\Support\MapLocation;
use App\Support\OrderMessage;
use App\Support\ShopSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ShopLocationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_numbers_and_full_google_maps_links_are_read_without_asking_google(): void
    {
        $this->assertSame('33.8938,35.5018', MapLocation::resolve(' 33.8938, 35.5018 '));
        $this->assertSame('33.8959,35.4784', MapLocation::resolve('https://maps.google.com/?q=33.8959,35.4784'));
        $this->assertSame('33.8938,35.5018', MapLocation::resolve('https://www.google.com/maps/place/Shop/@33.89,35.49,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d33.8938!4d35.5018'));
        $this->assertSame('33.89,35.49', MapLocation::resolve('https://www.google.com/maps/@33.89,35.49,15z'));
    }

    public function test_the_share_button_short_link_is_followed_to_find_the_pin(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/Shop/data=!4m2!3m1!1s0x0:0x0?entry=tts']),
            'www.google.com/maps/*' => Http::response('<script>x="/maps/place/Shop/data=!4m6!3m5!1s0x0:0x0!8m2!3d33.8886!4d35.4955"</script>'),
        ]);

        $this->assertSame('33.8886,35.4955', MapLocation::resolve('Check us out https://maps.app.goo.gl/AbC123xyz'));
    }

    public function test_the_map_centre_of_the_page_is_not_mistaken_for_the_shop(): void
    {
        // Google centres its page on the visitor's own area, not on the place.
        Http::fake([
            'www.google.com/maps/*' => Http::response('<meta content="https://maps.google.com/maps/api/staticmap?center=33.9968%2C35.6483&amp;zoom=15">'),
        ]);

        $this->assertNull(MapLocation::resolve('https://www.google.com/maps/place/Zaitunay+Bay'));
    }

    public function test_google_cookie_consent_page_is_skipped(): void
    {
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://consent.google.com/m?continue='.urlencode('https://www.google.com/maps/place/Shop/@33.9,35.5,17z/data=!3d33.9011!4d35.5122'),
            ]),
        ]);

        $this->assertSame('33.9011,35.5122', MapLocation::resolve('https://maps.app.goo.gl/AbC123xyz'));
    }

    public function test_links_outside_google_are_never_fetched(): void
    {
        Http::fake();

        $this->assertNull(MapLocation::resolve('https://example.com/shop'));
        $this->assertNull(MapLocation::resolve('http://maps.app.goo.gl/AbC123xyz'));
        Http::assertNothingSent();
    }

    public function test_settings_store_the_location_as_numbers_or_explain_what_to_paste(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        Http::fake([
            'maps.app.goo.gl/good' => Http::response('', 302, ['Location' => 'https://www.google.com/maps?q=33.8938,35.5018']),
            'maps.app.goo.gl/bad' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/Shop']),
            'www.google.com/maps/*' => Http::response('<html>no pin here</html>'),
        ]);

        $this->withToken($this->tokenFor($owner))
            ->patchJson('/api/v1/settings/profile', ['shop_location' => 'https://maps.app.goo.gl/good'])
            ->assertOk();
        $this->assertSame('33.8938,35.5018', $merchant->refresh()->settings['shop_location']);

        $this->withToken($this->tokenFor($owner))
            ->patchJson('/api/v1/settings/profile', ['shop_location' => 'https://maps.app.goo.gl/bad'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shop_location');
        $this->assertSame('33.8938,35.5018', $merchant->refresh()->settings['shop_location']);
    }

    public function test_pickup_customers_get_the_shop_address_as_text_never_a_link(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($owner))
            ->patchJson('/api/v1/settings/profile', [
                'fulfillment' => 'pickup',
                'shop_location' => '33.8938, 35.5018',
            ])
            ->assertOk();

        $merchant->refresh();
        $this->assertSame('33.8938,35.5018', $merchant->settings['shop_location']);
        $this->assertArrayNotHasKey('pickup_address', $merchant->settings, 'no link is stored as the address');
        $this->assertSame('', ShopSettings::for($merchant->fresh())->pickupAddress(), 'customers see no link');
    }

    public function test_a_stored_link_is_never_shown_to_customers_as_the_address(): void
    {
        $this->assertSame('', (new ShopSettings(['pickup_address' => 'https://www.google.com/maps?q=33.8938,35.5018']))->pickupAddress());
        $this->assertSame('Hamra St, Beirut', (new ShopSettings(['pickup_address' => 'Hamra St, Beirut']))->pickupAddress());
    }

    public function test_the_customers_pin_is_a_link_for_the_shop_and_driver_only(): void
    {
        $order = new Order(['fulfillment' => 'delivery', 'delivery_lat' => 33.8959, 'delivery_lng' => 35.4784, 'delivery_address' => 'Bliss St, Bldg 4']);
        $this->assertSame("Bliss St, Bldg 4\n📍 https://www.google.com/maps?q=33.8959,35.4784", OrderMessage::addressWithPin($order));
        $this->assertSame('Bliss St, Bldg 4', OrderMessage::addressText($order), 'the customer gets this text only');

        $pickup = new Order(['fulfillment' => 'pickup', 'delivery_lat' => 33.8959, 'delivery_lng' => 35.4784]);
        $this->assertStringNotContainsString('google', OrderMessage::addressWithPin($pickup));
    }

    public function test_a_delivery_area_pin_gets_its_driving_time_from_google_maps(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $merchant->forceFill(['settings' => ['shop_location' => '33.8938,35.5018']])->save();
        config(['services.google_maps.key' => 'test-key']);
        Http::fake([
            'routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '720s']]]),
        ]);

        $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/settings/zones', [
                'name' => 'Hamra',
                'location' => 'https://maps.google.com/?q=33.8959,35.4784',
                'deliveryFee' => 3,
                'deliveryMins' => 15,
            ])
            ->assertCreated()
            ->assertJsonPath('zone.location', '33.8959,35.4784')
            ->assertJsonPath('zone.delivery_mins', 12);
    }

    public function test_a_join_application_keeps_the_shop_pin_for_the_new_shop(): void
    {
        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Pin Shop',
            'contactName' => 'Nora',
            'businessType' => 'Restaurant / Café',
            'whatsapp' => '+96170999888',
            'country' => 'LB',
            'location' => '33.8959, 35.4784',
            'acceptedTerms' => true,
        ])->assertCreated();

        $request = OnboardingRequest::query()->where('shop_name', 'Pin Shop')->sole();
        $this->assertSame('33.8959,35.4784', $request->location);

        $this->withToken($this->tokenFor($this->platformAdmin()))
            ->postJson("/api/v1/admin/onboarding/{$request->id}/approve", ['password' => '123456', 'subscriptionStatus' => 'trial'])
            ->assertOk();

        $settings = Merchant::query()->where('name', 'Pin Shop')->sole()->settings;
        $this->assertSame('33.8959,35.4784', $settings['shop_location']);
        $this->assertStringNotContainsString('google', (string) ($settings['pickup_address'] ?? ''), 'the customer address is text');
    }
}
