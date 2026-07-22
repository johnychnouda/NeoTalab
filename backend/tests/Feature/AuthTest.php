<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
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

    public function test_register_creates_merchant_owner_and_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'business_name' => 'Joes Snacks',
            'business_type' => 'restaurant',
            'owner_name' => 'Joe',
            'email' => 'joe@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles'], 'merchant' => ['id', 'name', 'slug']]);

        $this->assertDatabaseHas('merchants', ['name' => 'Joes Snacks', 'business_type' => 'restaurant']);

        $user = User::where('email', 'joe@example.com')->firstOrFail();
        $this->assertNotNull($user->merchant_id);
        $this->assertTrue($user->hasRole(Roles::MERCHANT_OWNER));
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'business_name' => 'Shop',
            'owner_name' => 'X',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
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
