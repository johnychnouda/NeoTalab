<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\SystemAlert;
use Database\Seeders\PlatformSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** An hourly look at NeoTalab's own WhatsApp number: a real problem becomes an owner alert before a message fails. */
class PlatformWhatsAppWatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSettingsSeeder::class);
        Sleep::fake();
        config(['whatsapp.graph_api_base' => 'https://graph.facebook.com', 'whatsapp.graph_api_version' => 'v26.0']);
    }

    private function configured(): void
    {
        $setting = PlatformSetting::singleton();
        $setting->settings = array_merge($setting->settings ?? [], ['wa_phone_id' => '251311448055371', 'wa_access_token' => 'EAAX_secret_token']);
        $setting->save();
    }

    private function connected(): array
    {
        return ['id' => '251311448055371', 'display_phone_number' => '+961 70 207 078', 'verified_name' => 'NeoTalab', 'status' => 'CONNECTED', 'name_status' => 'APPROVED'];
    }

    public function test_a_number_meta_refuses_becomes_an_alert(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Error validating access token: Session has expired']], 400)]);

        $this->artisan('platform:check-whatsapp')->assertSuccessful();

        $alert = SystemAlert::query()->sole();
        $this->assertSame('error', $alert->level);
        $this->assertStringContainsString('Session has expired', $alert->message);
        $this->assertStringContainsString("NeoTalab's WhatsApp number", $alert->message);
    }

    public function test_a_working_number_raises_nothing(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response($this->connected(), 200)]);

        $this->artisan('platform:check-whatsapp')->assertSuccessful();

        $this->assertSame(0, SystemAlert::query()->count());
    }

    public function test_a_number_not_set_up_yet_raises_nothing(): void
    {
        Http::fake();

        $this->artisan('platform:check-whatsapp')->assertSuccessful();

        $this->assertSame(0, SystemAlert::query()->count());
        Http::assertNothingSent();
    }

    public function test_one_failed_try_that_works_on_the_second_is_not_an_alert(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Temporary problem']], 500)
            ->push($this->connected(), 200)]);

        $this->artisan('platform:check-whatsapp')->assertSuccessful();

        $this->assertSame(0, SystemAlert::query()->count());
    }
}
