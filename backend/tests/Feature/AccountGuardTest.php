<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * An unbound tenant means "see every shop", so only the platform owner may have one.
 * A disabled login is refused even with a session it opened before.
 */
class AccountGuardTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_shop_login_without_a_shop_sees_nothing(): void
    {
        [$merchant] = $this->merchantWithUser();
        Product::query()->forceCreate(['merchant_id' => $merchant->id, 'name' => 'Falafel', 'price' => 2.5]);

        $orphan = User::factory()->platformAdmin()->create();
        $orphan->assignRole(Roles::MERCHANT_OWNER);

        $this->withToken($this->tokenFor($orphan))->getJson('/api/v1/products')->assertForbidden();
        $this->withToken($this->tokenFor($orphan))->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_login_whose_shop_was_removed_is_refused(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $token = $this->tokenFor($owner);
        $merchant->delete();

        $this->withToken($token)->getJson('/api/v1/products')->assertForbidden();
    }

    public function test_disabled_login_loses_its_open_session(): void
    {
        [, $owner] = $this->merchantWithUser();
        $token = $this->tokenFor($owner);
        $this->withToken($token)->getJson('/api/v1/products')->assertOk();

        $owner->forceFill(['status' => 'disabled'])->save();
        $this->app['auth']->forgetGuards(); // a new request reads the user again

        $this->withToken($token)->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_platform_owner_still_sees_across_shops(): void
    {
        $admin = $this->platformAdmin();

        $this->withToken($this->tokenFor($admin))->getJson('/api/v1/admin/merchants')->assertOk();
    }
}
