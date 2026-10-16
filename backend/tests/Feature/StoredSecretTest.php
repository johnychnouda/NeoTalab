<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\StoredSecret;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StoredSecretTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_tokens_saved_before_encryption_still_work(): void
    {
        [$merchant] = $this->merchantWithUser();
        $merchant->update(['settings' => ['bot' => ['phone_id' => '123', 'access_token' => 'EAAG-plain']]]);

        $this->assertSame('EAAG-plain', MerchantWhatsAppCredentials::resolve($merchant->fresh())['access_token']);
    }

    public function test_the_migration_encrypts_plain_tokens_and_leaves_sealed_ones(): void
    {
        [$plain] = $this->merchantWithUser();
        [$sealed] = $this->merchantWithUser();
        $plain->update(['settings' => ['bot' => ['phone_id' => '1', 'access_token' => 'EAAG-plain']]]);
        $sealedValue = StoredSecret::seal('EAAG-sealed');
        $sealed->update(['settings' => ['bot' => ['phone_id' => '2', 'access_token' => $sealedValue]]]);
        $platform = PlatformSetting::singleton();
        $platform->settings = ['wa_access_token' => 'EAAX-platform'];
        $platform->save();

        (require database_path('migrations/2026_10_16_120000_encrypt_stored_whatsapp_tokens.php'))->up();

        $stored = $plain->fresh()->settings['bot']['access_token'];
        $this->assertNotSame('EAAG-plain', $stored);
        $this->assertSame('EAAG-plain', MerchantWhatsAppCredentials::resolve($plain->fresh())['access_token']);
        $this->assertSame($sealedValue, $sealed->fresh()->settings['bot']['access_token']);
        $this->assertNotSame('EAAX-platform', PlatformSetting::singleton()->fresh()->settings['wa_access_token']);
        $this->assertSame('EAAX-platform', MerchantWhatsAppCredentials::platformAccessToken());
    }
}
