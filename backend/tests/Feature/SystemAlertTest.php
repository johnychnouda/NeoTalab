<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\SystemAlert;
use App\Support\Roles;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SystemAlertTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlatformSettingsSeeder::class);
        RateLimiter::clear('system-alert-notify');
    }

    private function recipients(array $settings): void
    {
        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], $settings);
        $platform->save();
    }

    public function test_logged_problems_become_one_alert_per_problem_with_a_count(): void
    {
        [$merchant] = $this->merchantWithUser();

        Log::warning('Daily report failed', ['merchant_id' => $merchant->id, 'error' => 'timeout']);
        Log::warning('Daily report failed', ['merchant_id' => $merchant->id, 'error' => 'timeout again']);
        Log::info('Just information');

        $this->assertSame(1, SystemAlert::count());
        $alert = SystemAlert::first();
        $this->assertSame(2, $alert->occurrences);
        $this->assertSame($merchant->id, $alert->merchant_id);
        $this->assertSame('timeout again', $alert->context['error']);
    }

    public function test_secrets_are_not_stored(): void
    {
        Log::error('Something broke', ['access_token' => 'EAAX-secret', 'status' => 500]);

        $context = SystemAlert::first()->context;
        $this->assertArrayNotHasKey('access_token', $context);
        $this->assertSame('500', $context['status']);
    }

    public function test_errors_email_and_whatsapp_the_owner_once_while_open(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $e) use (&$sent) {
            $sent[] = array_map(fn ($address) => $address->getAddress(), $e->message->getTo());
        });
        $this->recipients(['wa_phone_id' => '999', 'wa_access_token' => 'EAAX', 'alert_email' => 'owner@neotalab.com', 'alert_whatsapp' => '+961 70 000 202']);

        foreach ([1, 2] as $night) { // the same failure twice (same place in the code)
            report(new RuntimeException('Nightly database backup failed — check the API logs.'));
        }

        $this->assertCount(1, $sent);
        $this->assertSame(['owner@neotalab.com'], $sent[0]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '999/messages')
            && $r['to'] === '96170000202'
            && $r['template']['name'] === 'system_alert'
            && str_contains($r['template']['components'][0]['parameters'][0]['text'], 'backup failed'));
        $this->assertNotNull(SystemAlert::first()->notified_at);
    }

    public function test_a_join_request_emails_and_whatsapps_the_owner(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        $subjects = [];
        Event::listen(MessageSent::class, function (MessageSent $e) use (&$subjects) {
            $subjects[] = $e->message->getSubject();
        });
        $this->recipients(['wa_phone_id' => '999', 'wa_access_token' => 'EAAX', 'alert_email' => 'neotalab@gmail.com', 'alert_whatsapp' => '+96170161659']);

        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Join Shop', 'contactName' => 'Ahmad', 'businessType' => 'Restaurant / Café',
            'whatsapp' => '+96178887777', 'city' => 'Zahle', 'region' => 'Bekaa', 'street' => 'Main road',
            'country' => 'LB', 'acceptedTerms' => true,
        ])->assertCreated();

        $this->assertSame(['NeoTalab: new join request from Join Shop'], $subjects);
        Http::assertSent(fn ($r) => $r['to'] === '96170161659'
            && str_contains($r['template']['components'][0]['parameters'][0]['text'], 'New join request: Join Shop (Zahle, Bekaa)'));
        $this->assertSame(0, SystemAlert::count());
    }

    public function test_a_join_request_is_saved_even_when_the_owner_cannot_be_reached(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131030]], 400)]);
        $this->recipients(['wa_phone_id' => '999', 'wa_access_token' => 'EAAX', 'alert_whatsapp' => '+96170161659']);

        $this->postJson('/api/v1/onboarding/apply', [
            'shopName' => 'Join Shop', 'contactName' => 'Ahmad', 'businessType' => 'Restaurant / Café',
            'whatsapp' => '+96178887777', 'city' => 'Zahle', 'region' => 'Bekaa', 'street' => 'Main road',
            'country' => 'LB', 'acceptedTerms' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('onboarding_requests', ['shop_name' => 'Join Shop', 'status' => 'pending']);
    }

    public function test_warnings_stay_in_the_inbox_without_notifying(): void
    {
        Http::fake();
        $this->recipients(['wa_phone_id' => '999', 'wa_access_token' => 'EAAX', 'alert_whatsapp' => '+96170000202']);

        Log::warning('Voice note could not be handled');

        $this->assertSame(1, SystemAlert::count());
        Http::assertNothingSent();
    }

    public function test_a_resolved_problem_that_comes_back_reopens_and_notifies_again(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
        $this->recipients(['wa_phone_id' => '999', 'wa_access_token' => 'EAAX', 'alert_whatsapp' => '+96170000202']);
        $token = $this->tokenFor($this->platformAdmin());

        Log::error('WhatsApp send failed');
        $id = SystemAlert::first()->id;
        $this->withToken($token)->postJson("/api/v1/admin/system-alerts/{$id}/resolve")->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin/system-alerts')->assertOk()->assertJsonCount(0, 'alerts');

        Log::error('WhatsApp send failed');

        $this->withToken($token)->getJson('/api/v1/admin/system-alerts')->assertOk()
            ->assertJsonCount(1, 'alerts')->assertJsonPath('alerts.0.occurrences', 1);
        Http::assertSentCount(2);
    }

    public function test_owner_can_list_resolve_all_and_send_a_test(): void
    {
        $token = $this->tokenFor($this->platformAdmin());
        Log::error('First problem');
        Log::warning('Second problem');

        $this->withToken($token)->getJson('/api/v1/admin/system-alerts')->assertOk()->assertJsonCount(2, 'alerts');
        $this->withToken($token)->postJson('/api/v1/admin/system-alerts/resolve-all')->assertOk()->assertJsonPath('resolved', 2);
        $this->withToken($token)->getJson('/api/v1/admin/system-alerts?resolved=1')->assertOk()->assertJsonCount(2, 'alerts');

        // No recipients yet: the test says both channels are off.
        $this->withToken($token)->postJson('/api/v1/admin/system-alerts/test')->assertOk()
            ->assertJsonPath('channels.email', 'off')->assertJsonPath('channels.whatsapp', 'off');

        // WhatsApp not ready (no platform number): the reason is shown, and no new alert is created.
        $this->recipients(['alert_whatsapp' => '+96170000202']);
        $before = SystemAlert::whereNull('resolved_at')->count();
        $this->withToken($token)->postJson('/api/v1/admin/system-alerts/test')->assertOk()
            ->assertJsonPath('channels.whatsapp', 'Platform WhatsApp credentials are not configured.');
        $this->assertSame($before, SystemAlert::whereNull('resolved_at')->count());
    }

    public function test_alert_recipients_are_saved_from_settings(): void
    {
        $token = $this->tokenFor($this->platformAdmin());

        $this->withToken($token)->patchJson('/api/v1/admin/platform-settings', [
            'alert_email' => 'owner@neotalab.com',
            'alert_whatsapp' => '+961 70 000 202',
        ])->assertOk()
            ->assertJsonPath('data.alertEmail', 'owner@neotalab.com')
            ->assertJsonPath('data.alertWhatsapp', '+961 70 000 202');

        $this->withToken($token)->patchJson('/api/v1/admin/platform-settings', ['alert_email' => 'not-an-email'])
            ->assertUnprocessable();
    }

    public function test_the_test_says_when_email_is_not_set_up_on_the_server(): void
    {
        config(['mail.default' => 'log']);
        $this->recipients(['alert_email' => 'owner@neotalab.com']);

        $this->withToken($this->tokenFor($this->platformAdmin()))->postJson('/api/v1/admin/system-alerts/test')->assertOk()
            ->assertJsonPath('channels.email', 'Email sending is not set up on the server yet (MAIL_MAILER).');
    }

    public function test_only_the_platform_owner_sees_system_alerts(): void
    {
        [, $user] = $this->merchantWithUser(Roles::MERCHANT_OWNER);

        $this->withToken($this->tokenFor($user))->getJson('/api/v1/admin/system-alerts')->assertForbidden();
        $this->withToken($this->tokenFor($user))->postJson('/api/v1/admin/system-alerts/test')->assertForbidden();
    }

    public function test_launch_checklist_shows_what_is_set_up(): void
    {
        config(['ai.driver' => 'fake', 'whatsapp.app_secret' => 'shh', 'filesystems.disks.backups.bucket' => null]);
        $token = $this->tokenFor($this->platformAdmin());

        $checks = collect($this->withToken($token)->getJson('/api/v1/admin/system-status')->assertOk()->json('checks'))->keyBy('key');

        $this->assertFalse($checks['ai']['ok']);
        $this->assertSame('required', $checks['ai']['level']);
        $this->assertTrue($checks['whatsapp_secret']['ok']);
        $this->assertFalse($checks['backups']['ok']);
        $this->assertFalse($checks['scheduler']['ok'], 'no heartbeat yet');

        $this->artisan('schedule:run');
        $checks = collect($this->withToken($token)->getJson('/api/v1/admin/system-status')->json('checks'))->keyBy('key');
        $this->assertTrue($checks['scheduler']['ok']);
    }

    public function test_launch_checklist_is_for_the_platform_owner_only(): void
    {
        [, $user] = $this->merchantWithUser();

        $this->withToken($this->tokenFor($user))->getJson('/api/v1/admin/system-status')->assertForbidden();
    }
}
