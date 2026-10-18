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

    public function test_once_approved_the_welcome_goes_as_a_template_so_it_reaches_a_new_shop(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
        config(['app.frontend_url' => 'https://neotalab.com']);
        $platform = PlatformSetting::singleton();
        $platform->settings = array_merge($platform->settings ?? [], [
            'wa_phone_id' => '111222333', 'wa_access_token' => 'EAAX_platform',
            'wa_templates' => ['neotalab_welcome' => 'APPROVED'],
        ]);
        $platform->save();

        $token = $this->tokenFor($this->platformAdmin());
        [$merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['name' => 'Foren', 'slug' => 'foren', 'whatsapp_number' => '+96171112233', 'phone' => '+96171112233']);

        $this->withToken($token)->postJson("/api/v1/admin/merchants/{$merchant->id}/welcome", ['otp' => '123456'])
            ->assertOk()->assertJsonPath('sent', true);

        Http::assertSent(fn ($r) => str_contains($r->url(), '111222333/messages')
            && $r['type'] === 'template' && $r['template']['name'] === 'neotalab_welcome'
            && array_column($r['template']['components'][0]['parameters'], 'text')[0] === 'Foren'
            && array_column($r['template']['components'][0]['parameters'], 'text')[1] === 'https://neotalab.com/backoffice/foren'
            && array_column($r['template']['components'][0]['parameters'], 'text')[3] === '123456');
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
