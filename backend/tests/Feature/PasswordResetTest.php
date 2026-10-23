<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['app.frontend_url' => 'https://neotalab.com']);
    }

    public function test_a_reset_link_is_emailed_and_points_to_the_website(): void
    {
        Notification::fake();
        $owner = $this->platformAdmin();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $owner->email])
            ->assertOk()->assertJsonPath('message', 'If that email has an account, a reset link is on its way.');

        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $n) use ($owner) {
            $url = $n->toMail($owner)->actionUrl;

            return str_starts_with($url, 'https://neotalab.com/reset-password?token=')
                && str_contains($url, 'email='.urlencode($owner->email));
        });
    }

    public function test_an_unknown_email_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()->assertJsonPath('message', 'If that email has an account, a reset link is on its way.');

        Notification::assertNothingSent();
    }

    public function test_team_members_get_no_reset_link(): void
    {
        Notification::fake();
        [$merchant] = $this->merchantWithUser();
        $staff = User::factory()->forMerchant($merchant)->create();
        $staff->assignRole(Roles::MERCHANT_STAFF);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $staff->email])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_the_link_sets_a_new_password_once_and_signs_out_old_sessions(): void
    {
        $owner = $this->platformAdmin();
        $oldToken = $this->tokenFor($owner);
        $token = Password::broker()->createToken($owner);

        $this->postJson('/api/v1/auth/reset-password', ['email' => $owner->email, 'token' => $token,
            'password' => 'Brand-new-pass-1', 'password_confirmation' => 'Brand-new-pass-1'])
            ->assertOk();

        $this->assertNotNull($owner->fresh()->password_changed_at);
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'Brand-new-pass-1'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'Brand-new-pass-1'])->assertOk();
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/reset-password', ['email' => $owner->email, 'token' => $token,
            'password' => 'Another-pass-2', 'password_confirmation' => 'Another-pass-2'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_a_wrong_or_forged_link_is_refused(): void
    {
        $owner = $this->platformAdmin();

        $this->postJson('/api/v1/auth/reset-password', ['email' => $owner->email, 'token' => 'forged-token',
            'password' => 'Brand-new-pass-1', 'password_confirmation' => 'Brand-new-pass-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/v1/auth/reset-password', ['email' => $owner->email, 'token' => 'x',
            'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
