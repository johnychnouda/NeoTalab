<?php

namespace Tests\Unit;

use App\Support\ShopSettings;
use PHPUnit\Framework\TestCase;

class ShopSettingsMapsUrlTest extends TestCase
{
    public function test_maps_url_from_lat_lng(): void
    {
        $settings = new ShopSettings(['shop_location' => '33.8938,35.5018']);

        $this->assertSame('https://www.google.com/maps?q=33.8938,35.5018', $settings->mapsUrl());
    }

    public function test_maps_url_falls_back_to_saved_google_link(): void
    {
        $settings = new ShopSettings([
            'shop_location_url' => 'https://maps.app.goo.gl/AbC123xyz',
        ]);

        $this->assertSame('https://maps.app.goo.gl/AbC123xyz', $settings->mapsUrl());
    }

    public function test_maps_url_null_when_unset(): void
    {
        $this->assertNull((new ShopSettings([]))->mapsUrl());
    }
}
