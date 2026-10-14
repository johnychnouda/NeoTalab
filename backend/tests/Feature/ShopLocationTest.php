<?php

namespace Tests\Feature;

use App\Support\MapLocation;
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
}
