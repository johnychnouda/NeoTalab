<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Support\MerchantWhatsAppCredentials;
use App\Support\Roles;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class MerchantWelcomeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlatformSettingsSeeder::class);
    }

    public function test_admin_can_send_merchant_welcome_via_platform_whatsapp(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200),
        ]);

        config(['app.frontend_url' => 'http://localhost:3001']);

        $admin = $this->platformAdmin();
        $token = $this->tokenFor($admin);

        $this->withToken($token)->patchJson('/api/v1/admin/platform-settings', [
            'wa_phone_id' => '111222333',
            'wa_access_token' => 'EAAX_platform',
        ])->assertOk();
        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], ['wa_templates' => ['neotalab_welcome_v4' => 'APPROVED', 'neotalab_code' => 'APPROVED']]);
        $platform->save();

        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'whatsapp_number' => '+96171112233',
            'phone' => '+96171112233',
        ]);

        $this->assertSame('111222333', PlatformSetting::singleton()->fresh()->settings['wa_phone_id'] ?? null);
        $this->assertSame('EAAX_platform', MerchantWhatsAppCredentials::platformAccessToken());

        $this->withToken($token)
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/welcome", [
                'otp' => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('sent', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '111222333/messages')
                && str_contains($request->body(), '123456');
        });
    }

    public function test_once_approved_the_welcome_and_the_code_go_as_two_templates_and_the_code_is_only_in_the_second(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
        config(['app.frontend_url' => 'https://neotalab.com']);
        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], [
            'wa_phone_id' => '111222333', 'wa_access_token' => 'EAAX_platform',
            'wa_templates' => ['neotalab_welcome_v4' => 'APPROVED', 'neotalab_code' => 'APPROVED'],
        ]);
        $platform->save();

        $token = $this->tokenFor($this->platformAdmin());
        [$merchant, $user] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Foren', 'slug' => 'foren', 'whatsapp_number' => '+96171112233', 'phone' => '+96171112233']);

        $this->withToken($token)->postJson("/api/v1/admin/merchants/{$merchant->id}/welcome", ['otp' => '123456'])
            ->assertOk()->assertJsonPath('sent', true);

        $sent = Http::recorded()->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), '111222333/messages'))->values();
        $this->assertCount(2, $sent);

        $welcome = $sent[0];
        $this->assertSame('neotalab_welcome_v4', $welcome['template']['name']);
        $this->assertSame(['Foren', $user->email], array_column($welcome['template']['components'][0]['parameters'], 'text'));
        $this->assertSame(['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'foren']]], $welcome['template']['components'][1], 'the login is a button that ends in the shop slug');
        $this->assertStringNotContainsString('http', json_encode($welcome['template']['components'][0]), 'no link is typed into the text');
        $this->assertStringNotContainsString('123456', $welcome->body(), 'the welcome carries no code');

        $code = $sent[1];
        $this->assertSame('neotalab_code', $code['template']['name']);
        $this->assertSame('123456', $code['template']['components'][0]['parameters'][0]['text']);
        $this->assertSame(['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => '123456']]], $code['template']['components'][1]);
    }

    public function test_nothing_is_sent_unless_both_the_welcome_and_the_code_template_are_approved(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['whatsapp_number' => '+96171112233', 'phone' => '+96171112233']);

        foreach ([['neotalab_welcome_v4' => 'APPROVED'], ['neotalab_code' => 'APPROVED']] as $templates) {
            $platform = PlatformSetting::singleton();
            $platform->settings = array_merge($platform->settings ?? [], ['wa_phone_id' => '111222333', 'wa_access_token' => 'EAAX_platform', 'wa_templates' => $templates]);
            $platform->save();

            $this->withToken($token)->postJson("/api/v1/admin/merchants/{$merchant->id}/welcome", ['otp' => '123456', 'regenerateAccess' => true])
                ->assertOk()->assertJsonPath('sent', false);
        }

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '111222333/messages'));
    }

    public function test_a_code_is_never_sent_as_plain_text_before_the_template_is_approved(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], ['wa_phone_id' => '111222333', 'wa_access_token' => 'EAAX_platform']);
        $platform->save();

        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['whatsapp_number' => '+96171112233', 'phone' => '+96171112233']);

        $this->withToken($token)->postJson("/api/v1/admin/merchants/{$merchant->id}/welcome", [
            'otp' => '123456', 'regenerateAccess' => true,
        ])->assertOk()->assertJsonPath('sent', false);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '111222333/messages'));
    }

    public function test_admin_can_regenerate_access_and_fallback_when_platform_whatsapp_missing(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001']);

        $admin = $this->platformAdmin();
        $token = $this->tokenFor($admin);

        [$merchant, $user] = $this->merchantWithUser(Roles::MERCHANT_OWNER, [
            'whatsapp_number' => '+96171112233',
            'phone' => '+96171112233',
        ]);

        $user->createToken('api');
        $this->assertSame(1, $user->tokens()->count());

        $this->withToken($token)
            ->postJson("/api/v1/admin/merchants/{$merchant->id}/welcome", [
                'otp' => '654321',
                'regenerateAccess' => true,
            ])
            ->assertOk()
            ->assertJsonPath('sent', false)
            ->assertJsonPath('otp', '654321')
            ->assertJsonPath('loginEmail', $user->email);

        $user->refresh();
        $this->assertNull($user->password_changed_at);
        $this->assertTrue(Hash::check('654321', $user->password));
        $this->assertSame(0, $user->tokens()->count());
    }
}
