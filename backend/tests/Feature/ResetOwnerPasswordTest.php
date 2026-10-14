<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ResetOwnerPasswordTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_owner_gets_a_new_password_and_old_sessions_end(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $owner = $this->platformAdmin();
        $this->tokenFor($owner);
        $this->merchantWithUser(); // shop users are never touched

        $this->assertSame(0, Artisan::call('owner:reset-password'));
        preg_match('/Password: (\S+)/', Artisan::output(), $m);

        $this->assertTrue(Hash::check($m[1], $owner->fresh()->password));
        $this->assertSame(0, $owner->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => $m[1]])->assertOk();
    }

    public function test_the_owner_login_can_move_to_a_new_email(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $owner = $this->platformAdmin();
        [, $shopUser] = $this->merchantWithUser();

        $this->assertSame(1, Artisan::call('owner:reset-password', ['--new-email' => $shopUser->email]));
        $this->assertSame(1, Artisan::call('owner:reset-password', ['--new-email' => 'not-an-email']));

        $this->assertSame(0, Artisan::call('owner:reset-password', ['--new-email' => 'Buyer@Example.com']));
        preg_match('/Password: (\S+)/', Artisan::output(), $m);

        $this->assertSame('buyer@example.com', $owner->fresh()->email);
        $this->postJson('/api/v1/auth/login', ['email' => 'buyer@example.com', 'password' => $m[1]])->assertOk();
    }
}
