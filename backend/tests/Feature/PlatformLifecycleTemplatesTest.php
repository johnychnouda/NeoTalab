<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\OnboardingRequest;
use App\Models\PlatformSetting;
use App\Models\SystemAlert;
use App\Services\ShopWhatsAppWatch;
use App\Services\SubscriptionStopService;
use App\Services\SystemAlertService;
use App\Services\TrialExpiryService;
use App\Services\WhatsApp\PlatformTemplateService;
use App\Support\Roles;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlatformLifecycleTemplatesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlatformSettingsSeeder::class);
        config(['whatsapp.platform_access_token' => 'test-token']);
        $this->fakeGraph();
        $this->platform(['wa_phone_id' => '111222333', 'wa_access_token' => 'EAAX_platform']);
    }

    /**
     * Meta answers 200 to everything, except what $overrides say (the first matching address wins).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeGraph(array $overrides = []): void
    {
        Http::swap(new HttpFactory);
        Http::fake($overrides + ['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']], 'display_phone_number' => '+961 70 111 222'], 200)]);
    }

    /** @param  array<string, mixed>  $values */
    private function platform(array $values): void
    {
        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], $values);
        $platform->save();
    }

    /** Marks these platform templates as approved by Meta. */
    private function approve(string ...$names): void
    {
        $this->platform(['wa_templates' => array_fill_keys($names, 'APPROVED')]);
    }

    /** @return list<Request> */
    private function platformSends(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_contains($r->url(), '111222333/messages'))->values()->all();
    }

    private function owner(string $locale = 'en', array $extra = []): Merchant
    {
        return $this->merchantWithUser(Roles::MERCHANT_OWNER, $extra + [
            'name' => 'Beirut Bites', 'locale' => $locale, 'whatsapp_number' => '+96171112233', 'phone' => '+96171112233',
        ])[0];
    }

    // ── the wording ─────────────────────────────────────────────────────────

    public function test_every_platform_template_follows_metas_rules(): void
    {
        foreach (PlatformTemplateService::definitions() as $name => $def) {
            if (isset($def['authentication'])) {
                continue; // Meta writes these
            }
            preg_match_all('/\{\{(\d+)\}\}/', $def['text'], $found);
            $this->assertCount(count(array_unique($found[1])), $found[1], "{$name}: no variable is used twice");
            $numbers = array_values(array_map('intval', array_unique($found[1])));
            $this->assertSame(range(1, count($numbers)), $numbers, "{$name}: variables are numbered 1, 2, 3…");
            $this->assertCount(count($numbers), $def['examples'], "{$name}: one example per variable");
            $this->assertDoesNotMatchRegularExpression('/^\s*\{\{/u', $def['text'], "{$name}: no variable at the start");
            $this->assertDoesNotMatchRegularExpression('/\{\{\d+\}\}\s*[.!?؟]?\s*$/u', $def['text'], "{$name}: no variable at the end");
            $this->assertLessThan(1024, mb_strlen($def['text']), $name);
        }
    }

    public function test_the_shop_owner_templates_exist_in_arabic_and_french_too(): void
    {
        $defs = PlatformTemplateService::definitions();
        foreach ([PlatformTemplateService::WELCOME, PlatformTemplateService::CODE, PlatformTemplateService::PAID, PlatformTemplateService::DAILY_REPORT,
            PlatformTemplateService::SHOP_PAUSED, PlatformTemplateService::WHATSAPP_DOWN] as $base) {
            foreach (['ar', 'fr'] as $language) {
                $name = PlatformTemplateService::nameFor($base, $language);
                $this->assertArrayHasKey($name, $defs);
                $this->assertSame($language, PlatformTemplateService::languageOf($name));
            }
        }
        $this->assertSame('Open my dashboard', $defs[PlatformTemplateService::WELCOME]['button_text'] ?? 'Open my dashboard');
        $this->assertTrue($defs['neotalab_welcome_v5_ar']['login_button']);
        $this->assertArrayNotHasKey('neotalab_welcome_v4_ar', $defs, 'the rejected name is not sent again');
        $this->assertStringNotContainsString('الدخول', $defs['neotalab_welcome_v5_ar']['text'], 'no "login" wording: Meta refused it');
        $this->assertArrayHasKey('button_text', $defs['neotalab_welcome_v4_fr']);
        $this->assertSame('en', PlatformTemplateService::languageOf(PlatformTemplateService::PAID));
    }

    public function test_the_language_picks_its_template_and_english_is_the_fallback(): void
    {
        $templates = app(PlatformTemplateService::class);
        $this->assertNull($templates->resolve(PlatformTemplateService::PAID, 'ar'), 'nothing approved yet');

        $this->approve('neotalab_paid');
        $this->assertSame(['name' => 'neotalab_paid', 'language' => 'en'], $templates->resolve(PlatformTemplateService::PAID, 'ar'));

        $this->approve('neotalab_paid', 'neotalab_paid_ar');
        $this->assertSame(['name' => 'neotalab_paid_ar', 'language' => 'ar'], $templates->resolve(PlatformTemplateService::PAID, 'ar'));
        $this->assertSame('neotalab_paid', $templates->resolve(PlatformTemplateService::PAID, 'fr')['name']);
        $this->assertSame('neotalab_paid', $templates->resolve(PlatformTemplateService::PAID, 'de')['name']);
    }

    // ── the shop stopped ────────────────────────────────────────────────────

    public function test_a_shop_whose_trial_ended_is_told_in_its_language_from_neotalabs_number(): void
    {
        $this->approve('neotalab_shop_paused', 'neotalab_shop_paused_ar');
        $merchant = $this->owner('ar', ['status' => 'active', 'trial_ends_at' => now()->subHour(), 'settings' => ['subscription_status' => 'trial']]);

        $this->assertSame(1, app(TrialExpiryService::class)->expireDue());

        $sent = $this->platformSends();
        $this->assertCount(1, $sent);
        $this->assertSame('96171112233', $sent[0]['to']);
        $this->assertSame('neotalab_shop_paused_ar', $sent[0]['template']['name']);
        $this->assertSame('ar', $sent[0]['template']['language']['code']);
        $this->assertSame(['Beirut Bites', 'انتهت تجربتك المجانية'], array_column($sent[0]['template']['components'][0]['parameters'], 'text'));
        $this->assertSame('suspended', $merchant->fresh()->status);
    }

    public function test_a_shop_stopped_for_an_overdue_payment_is_told_why(): void
    {
        $this->approve('neotalab_shop_paused');
        $merchant = $this->owner('en', ['status' => 'active', 'subscription_ends_at' => now()->subDays(4), 'settings' => ['subscription_status' => 'paid']]);

        $this->assertSame(1, app(SubscriptionStopService::class)->stopDue());

        $sent = $this->platformSends();
        $this->assertCount(1, $sent, 'a paid shop that stopped used to get no message at all');
        $this->assertSame(['Beirut Bites', 'your payment is overdue'], array_column($sent[0]['template']['components'][0]['parameters'], 'text'));
        $this->assertSame('suspended', $merchant->fresh()->status);
    }

    public function test_stopping_a_shop_still_works_when_its_template_is_not_approved_yet(): void
    {
        $merchant = $this->owner('en', ['status' => 'active', 'subscription_ends_at' => now()->subDays(4), 'settings' => ['subscription_status' => 'paid']]);

        $this->assertSame(1, app(SubscriptionStopService::class)->stopDue());

        $this->assertSame([], $this->platformSends(), 'never plain text to someone who has not written to the number');
        $this->assertSame('suspended', $merchant->fresh()->status);
    }

    public function test_a_shop_the_owner_stops_or_restarts_by_hand_is_told_on_whatsapp(): void
    {
        $this->approve('neotalab_shop_paused', 'neotalab_shop_active');
        $merchant = $this->owner('en', ['status' => 'active', 'settings' => ['subscription_status' => 'paid']]);
        $token = $this->tokenFor($this->platformAdmin());

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['status' => 'suspended'])->assertOk();
        $paused = $this->platformSends();
        $this->assertCount(1, $paused);
        $this->assertSame(['Beirut Bites', 'your account was paused by NeoTalab'], array_column($paused[0]['template']['components'][0]['parameters'], 'text'));

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['status' => 'active'])->assertOk();
        $sent = $this->platformSends();
        $this->assertCount(2, $sent);
        $this->assertSame('neotalab_shop_active', $sent[1]['template']['name']);

        $this->withToken($token)->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['name' => 'Beirut Bites 2'])->assertOk();
        $this->assertCount(2, $this->platformSends(), 'editing something else tells nobody');
    }

    // ── the shop's own WhatsApp stopped working ─────────────────────────────

    private function connectedShop(string $locale = 'en', ?string $tokenExpires = null): Merchant
    {
        return $this->owner($locale, ['status' => 'active', 'settings' => [
            'subscription_status' => 'paid',
            'bot' => ['phone_id' => '555', 'access_token' => 'EAAX_shop', 'token_expires' => $tokenExpires, 'status' => 'active'],
        ]]);
    }

    public function test_a_shop_whose_whatsapp_broke_is_told_to_reconnect_and_the_owner_sees_an_alert(): void
    {
        $this->approve('neotalab_whatsapp_down', 'neotalab_whatsapp_down_fr');
        $merchant = $this->connectedShop('fr');
        $this->fakeGraph(['graph.facebook.com/*/555*' => Http::response(['error' => ['message' => 'Session has expired']], 401)]);

        $this->assertSame(1, app(ShopWhatsAppWatch::class)->run());

        $sent = $this->platformSends();
        $this->assertCount(1, $sent);
        $this->assertSame('neotalab_whatsapp_down_fr', $sent[0]['template']['name']);
        $this->assertSame(['Beirut Bites', 'elle a cessé de fonctionner'], array_column($sent[0]['template']['components'][0]['parameters'], 'text'));
        $this->assertSame('error', $merchant->fresh()->settings['bot']['status']);
        $this->assertNotNull(SystemAlert::query()->where('message', 'like', 'Beirut Bites: its WhatsApp connection is not working%')->first());
    }

    public function test_the_shop_is_reminded_every_three_days_not_every_day_and_stops_when_fixed(): void
    {
        $this->approve('neotalab_whatsapp_down');
        $merchant = $this->connectedShop();
        $this->fakeGraph(['graph.facebook.com/*/555*' => Http::response(['error' => ['message' => 'Session has expired']], 401)]);
        $watch = app(ShopWhatsAppWatch::class);
        $day = Carbon::parse('2026-10-14 10:20', 'Asia/Beirut');

        $this->assertSame(1, $watch->run($day));
        $this->assertSame(0, $watch->run($day->copy()->addDay()), 'told yesterday');
        $this->assertSame(0, $watch->run($day->copy()->addDays(2)));
        $this->assertSame(1, $watch->run($day->copy()->addDays(3)), 'still broken: told again');
        $this->assertCount(2, $this->platformSends());

        $this->fakeGraph(); // Meta answers properly again
        $this->assertSame(0, $watch->run($day->copy()->addDays(9)), 'working again');
        $this->assertArrayNotHasKey('wa_down_notified_at', $merchant->fresh()->settings, 'a later break is told straight away');
    }

    public function test_a_token_that_expires_within_a_week_is_announced_with_its_date(): void
    {
        $this->approve('neotalab_whatsapp_down');
        $this->connectedShop('en', now()->addDays(5)->toIso8601String());
        Carbon::setTestNow(Carbon::parse('2026-10-14 10:20', 'Asia/Beirut'));

        $merchant = Merchant::query()->where('name', 'Beirut Bites')->sole();
        $settings = $merchant->settings;
        $settings['bot']['token_expires'] = '2026-10-19T00:00:00+00:00';
        $merchant->forceFill(['settings' => $settings])->save();

        $this->assertSame(1, app(ShopWhatsAppWatch::class)->run());

        $this->assertSame('it expires on 19 October', $this->platformSends()[0]['template']['components'][0]['parameters'][1]['text']);
    }

    public function test_a_healthy_shop_is_left_alone(): void
    {
        $this->approve('neotalab_whatsapp_down');
        $this->connectedShop('en', now()->addDays(40)->toIso8601String());

        $this->assertSame(0, app(ShopWhatsAppWatch::class)->run());
        $this->assertSame([], $this->platformSends());
        $this->assertSame(0, SystemAlert::count());
    }

    // ── people who apply to join ────────────────────────────────────────────

    private function apply(string $phone = '+96178887777'): void
    {
        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Join Shop', 'contactName' => 'Ahmad', 'businessType' => 'Restaurant / Café',
            'whatsapp' => $phone, 'city' => 'Zahle', 'region' => 'Bekaa', 'street' => 'Main road', 'country' => 'LB', 'acceptedTerms' => true,
        ])->assertCreated();
    }

    public function test_an_applicant_is_told_their_request_arrived_and_later_that_it_was_declined(): void
    {
        $this->approve('neotalab_request_received', 'neotalab_request_declined');

        $this->apply();
        $received = collect($this->platformSends())->filter(fn ($r) => $r['to'] === '96178887777')->sole();
        $this->assertSame('neotalab_request_received', $received['template']['name']);
        $this->assertSame(['Ahmad', 'Join Shop'], array_column($received['template']['components'][0]['parameters'], 'text'));

        $token = $this->tokenFor($this->platformAdmin());
        $id = OnboardingRequest::query()->latest()->value('id');
        $this->withToken($token)->deleteJson("/api/v1/admin/onboarding/{$id}")->assertOk();

        $declined = collect($this->platformSends())->filter(fn ($r) => $r['to'] === '96178887777' && $r['template']['name'] === 'neotalab_request_declined')->sole();
        $this->assertSame(['Ahmad', 'Join Shop'], array_column($declined['template']['components'][0]['parameters'], 'text'));
    }

    public function test_an_applicant_who_used_the_page_in_arabic_is_answered_in_arabic(): void
    {
        $this->approve('neotalab_request_received', 'neotalab_request_received_ar');

        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'سناك أبو علي', 'contactName' => 'علي', 'businessType' => 'Restaurant / Café', 'language' => 'ar',
            'whatsapp' => '+96178887777', 'city' => 'Zahle', 'region' => 'Bekaa', 'street' => 'Main road', 'country' => 'LB', 'acceptedTerms' => true,
        ])->assertCreated();

        $this->assertSame('ar', OnboardingRequest::query()->latest()->value('language'));
        $sent = collect($this->platformSends())->filter(fn ($r) => $r['to'] === '96178887777')->sole();
        $this->assertSame('neotalab_request_received_ar', $sent['template']['name']);
        $this->assertSame('ar', $sent['template']['language']['code']);
    }

    public function test_an_unknown_language_on_the_join_form_is_refused(): void
    {
        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Join Shop', 'contactName' => 'Ahmad', 'businessType' => 'Restaurant / Café', 'language' => 'xx',
            'whatsapp' => '+96178887777', 'city' => 'Zahle', 'region' => 'Bekaa', 'street' => 'Main road', 'country' => 'LB', 'acceptedTerms' => true,
        ])->assertUnprocessable();
    }

    public function test_the_alert_language_chosen_in_settings_picks_the_template_version(): void
    {
        $this->approve('system_alert', 'system_alert_fr');
        $this->platform(['alert_whatsapp' => '+96170161659', 'alert_language' => 'fr']);

        app(SystemAlertService::class)->sendToOwner('Warning: the backup failed', 'subject', '');

        $sent = collect($this->platformSends())->filter(fn ($r) => $r['to'] === '96170161659')->sole();
        $this->assertSame('system_alert_fr', $sent['template']['name']);
        $this->assertSame('fr', $sent['template']['language']['code']);

        $this->platform(['alert_language' => 'en']);
        app(SystemAlertService::class)->sendToOwner('Warning: the backup failed', 'subject', '');
        $this->assertSame('system_alert', collect($this->platformSends())->filter(fn ($r) => $r['to'] === '96170161659')->last()['template']['name']);
    }

    public function test_the_public_form_cannot_be_used_to_message_strangers_over_and_over(): void
    {
        $this->approve('neotalab_request_received');
        RateLimiter::clear('join-ack-all');

        $this->apply('+96178887777');
        OnboardingRequest::query()->update(['status' => 'rejected']);
        $this->apply('+96178887777');

        $toApplicant = collect($this->platformSends())->filter(fn ($r) => $r['to'] === '96178887777');
        $this->assertCount(1, $toApplicant, 'one acknowledgement per number a day');
    }

    public function test_an_application_works_when_the_template_is_not_approved_yet(): void
    {
        $this->apply();

        $this->assertDatabaseHas('onboarding_requests', ['shop_name' => 'Join Shop', 'status' => 'pending']);
        $this->assertSame([], collect($this->platformSends())->filter(fn ($r) => $r['to'] === '96178887777')->all());
    }
}
