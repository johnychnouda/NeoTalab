<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Support\MerchantWhatsAppCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_number_typed_in_by_hand_is_asked_from_meta_once_and_saved(): void
    {
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+961 70 000 111', 'status' => 'CONNECTED'])]);
        PlatformSetting::singleton()->update(['settings' => ['wa_phone_id' => '999']]);

        $this->assertSame('96170000111', MerchantWhatsAppCredentials::platformNumberDigits());
        $this->assertSame('96170000111', MerchantWhatsAppCredentials::platformNumberDigits());

        Http::assertSentCount(1);
        $this->assertSame('+961 70 000 111', PlatformSetting::singleton()->settings['wa_display_phone']);
    }

    public function test_no_number_is_known_when_neotalabs_number_is_not_set(): void
    {
        Http::fake();

        $this->assertSame('', MerchantWhatsAppCredentials::platformNumberDigits());
        Http::assertNothingSent();
    }
}
