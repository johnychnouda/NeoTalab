<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_public_self_registration_is_disabled(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'business_name' => 'Shop',
            'owner_name' => 'X',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_login_is_rate_limited_per_email(): void
    {
        $this->merchantWithUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'victim@example.com', 'password' => 'wrong-'.$i])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'victim@example.com', 'password' => 'wrong-again'])
            ->assertStatus(429);
    }

    public function test_tokens_expire_after_thirty_days(): void
    {
        [, $user] = $this->merchantWithUser();
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_login_returns_token_and_merchant(): void
    {
        [$merchant, $user] = $this->merchantWithUser();
        $user->update(['email' => 'a@b.com', 'password' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id'], 'merchant' => ['id']])
            ->assertJsonPath('merchant.id', $merchant->id);
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $this->merchantWithUser();

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@x.com', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_me_and_logout(): void
    {
        [, $user] = $this->merchantWithUser();
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_protected_route_requires_auth(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
