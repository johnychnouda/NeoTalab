<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Services\WhatsApp\PlatformTemplateService;
use Database\Seeders\PlatformSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** What NeoTalab sends to Meta when it submits its own templates for review. */
class PlatformTemplateSubmissionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array> the request bodies, by template name */
    private function submitted(): array
    {
        $this->seed(PlatformSettingsSeeder::class);
        config(['app.frontend_url' => 'https://neotalab.com', 'whatsapp.platform_access_token' => 'tok']);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1', 'status' => 'PENDING'], 200)]);
        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], ['wa_waba_id' => '999', 'wa_phone_id' => '111', 'wa_access_token' => 'tok']);
        $platform->save();

        app(PlatformTemplateService::class)->submitAll('999', 'tok');

        return Http::recorded()->map(fn ($p) => $p[0]->data())->keyBy('name')->all();
    }

    public function test_the_login_messages_carry_the_link_as_a_button_not_as_text(): void
    {
        $sent = $this->submitted();

        foreach ([PlatformTemplateService::WELCOME, PlatformTemplateService::ACCESS] as $name) {
            $components = collect($sent[$name]['components']);
            $body = $components->firstWhere('type', 'BODY');
            $button = $components->firstWhere('type', 'BUTTONS')['buttons'][0];

            $this->assertStringNotContainsString('http', $body['text']);
            $this->assertSame(2, preg_match_all('/\{\{\d\}\}/', $body['text']), 'only the shop name and the email change');
            $this->assertSame('URL', $button['type']);
            $this->assertSame('https://neotalab.com/backoffice/{{1}}', $button['url']);
            $this->assertSame(['https://neotalab.com/backoffice/abou-ali-snack'], $button['example']);
        }
    }

    public function test_the_code_is_one_authentication_template_with_a_copy_button(): void
    {
        $sent = $this->submitted()[PlatformTemplateService::CODE];

        $this->assertSame('AUTHENTICATION', $sent['category']);
        $this->assertSame('COPY_CODE', $sent['components'][1]['buttons'][0]['otp_type']);
    }

    public function test_the_nightly_report_has_enough_words_around_its_seven_values(): void
    {
        $text = collect($this->submitted()[PlatformTemplateService::DAILY_REPORT]['components'])->firstWhere('type', 'BODY')['text'];

        $this->assertSame(7, preg_match_all('/\{\{\d\}\}/', $text));
        $words = str_word_count(preg_replace('/\{\{\d\}\}/', '', $text));
        $this->assertGreaterThanOrEqual(35, $words, 'Meta refuses a text with too many values for its length');
        $this->assertDoesNotMatchRegularExpression('/\{\{\d\}\}\s*$/', trim($text), 'a text may not end with a value');
    }
}
