<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminMerchantService;
use App\Services\AuthService;
use App\Support\Roles;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The activation code is the shop's first password: it lasts 24 hours, and once it has been used
 * the shop has one more hour to choose its own password.
 */
class ShopActivationCodeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Asia/Beirut'));
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlatformSettingsSeeder::class);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
        [$merchant, $this->owner] = $this->merchantWithUser(Roles::MERCHANT_OWNER, ['whatsapp_number' => '+96171112233', 'phone' => '+96171112233']);
        $this->merchant = $merchant;
        $this->issueCode('482913');
    }

    private $merchant;

    private function issueCode(string $code): void
    {
        $token = $this->tokenFor($this->platformAdmin());
        $this->withToken($token)->postJson("/api/v1/admin/merchants/{$this->merchant->id}/welcome", ['otp' => $code, 'regenerateAccess' => true])->assertOk();
    }

    private function login(string $password): ?string
    {
        try {
            app(AuthService::class)->login($this->owner->email, $password);

            return null;
        } catch (ValidationException $e) {
            return $e->errors()['email'][0];
        }
    }

    public function test_a_new_code_works_for_24_hours_and_then_says_it_has_expired(): void
    {
        $this->assertSame(Carbon::now()->addDay()->toIso8601String(), $this->owner->fresh()->activation_expires_at->toIso8601String());

        Carbon::setTestNow(Carbon::now()->addHours(23));
        $this->assertNull($this->login('482913'));

        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->assertStringContainsString('expired', (string) $this->login('482913'));
    }

    public function test_a_wrong_password_never_reveals_that_a_code_exists_or_has_expired(): void
    {
        Carbon::setTestNow(Carbon::now()->addDays(3));

        $this->assertSame('These credentials do not match our records.', $this->login('000000'));
    }

    public function test_once_used_the_code_gives_one_more_hour_to_choose_a_password(): void
    {
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->assertNull($this->login('482913'));

        Carbon::setTestNow(Carbon::now()->addMinutes(50));
        $this->assertNull($this->login('482913'));

        Carbon::setTestNow(Carbon::now()->addMinutes(30));
        $this->assertStringContainsString('expired', (string) $this->login('482913'));
    }

    public function test_choosing_a_password_ends_the_expiry(): void
    {
        Carbon::setTestNow(Carbon::now()->addHours(1));
        $this->assertNull($this->login('482913'));
        app(AuthService::class)->setInitialPassword($this->owner->fresh(), 'my-own-password-1');

        $this->assertNull($this->owner->fresh()->activation_expires_at);
        Carbon::setTestNow(Carbon::now()->addDays(30));
        $this->assertNull($this->login('my-own-password-1'));
    }

    public function test_approving_a_join_request_starts_the_24_hours(): void
    {
        $admin = $this->platformAdmin();
        $result = app(AdminMerchantService::class)->provisionFromOwnerPortal([
            'shopName' => 'Cedar Cafe', 'whatsappNumber' => '+96170999888', 'password' => '135790', 'businessType' => 'Restaurant / Café',
            'loginEmail' => 'cedar@example.com', 'country' => 'LB', 'subscriptionStatus' => 'pending', 'forcePasswordChange' => true,
        ]);

        $this->assertSame(Carbon::now()->addDay()->toIso8601String(), $result['user']->fresh()->activation_expires_at->toIso8601String());
        unset($admin);
    }
}
