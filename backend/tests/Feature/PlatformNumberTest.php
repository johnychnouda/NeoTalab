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

    public function test_a_number_typed_in_by_hand_is_asked_from_meta_once_and_remembered_without_touching_the_settings(): void
    {
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+961 70 000 111', 'status' => 'CONNECTED'])]);
        PlatformSetting::singleton()->update(['settings' => ['wa_phone_id' => '999']]);

        $this->assertSame('96170000111', MerchantWhatsAppCredentials::platformNumberDigits());
        $this->assertSame('96170000111', MerchantWhatsAppCredentials::platformNumberDigits());

        Http::assertSentCount(1);
        // The owner portal shows "connected with the QR" when this is set: a typed ID must not turn it on.
        $this->assertArrayNotHasKey('wa_display_phone', PlatformSetting::singleton()->settings);
    }

    public function test_when_meta_cannot_answer_it_is_not_asked_again_on_every_message(): void
    {
        config(['whatsapp.platform_access_token' => 'test-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);
        PlatformSetting::singleton()->update(['settings' => ['wa_phone_id' => '999']]);

        $this->assertSame('', MerchantWhatsAppCredentials::platformNumberDigits());
        $this->assertSame('', MerchantWhatsAppCredentials::platformNumberDigits());
        $this->assertSame('', MerchantWhatsAppCredentials::platformNumberDigits());

        Http::assertSentCount(1);
    }

    public function test_the_number_saved_by_the_qr_connection_is_used_without_asking_meta(): void
    {
        Http::fake();
        PlatformSetting::singleton()->update(['settings' => ['wa_phone_id' => '999', 'wa_display_phone' => '+961 70 000 222']]);

        $this->assertSame('96170000222', MerchantWhatsAppCredentials::platformNumberDigits());
        Http::assertNothingSent();
    }

    public function test_no_number_is_known_when_neotalabs_number_is_not_set(): void
    {
        Http::fake();

        $this->assertSame('', MerchantWhatsAppCredentials::platformNumberDigits());
        Http::assertNothingSent();
    }
}
