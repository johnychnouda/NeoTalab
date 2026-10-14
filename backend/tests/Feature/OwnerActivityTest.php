<?php

namespace Tests\Feature;

use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class OwnerActivityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_owner_history_is_kept_on_the_server_and_merchants_cannot_see_it(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $owner = $this->tokenFor($this->platformAdmin());

        $this->withToken($owner)->postJson('/api/v1/admin/activity', ['type' => 'billing', 'message' => 'Foren marked as paid — $290'])->assertCreated();
        $this->withToken($owner)->postJson('/api/v1/admin/activity', ['type' => 'hack', 'message' => 'x'])->assertUnprocessable();

        $this->withToken($owner)->getJson('/api/v1/admin/activity')->assertOk()
            ->assertJsonPath('activity.0.type', 'billing')->assertJsonPath('activity.0.message', 'Foren marked as paid — $290');

        $this->app['auth']->forgetGuards();
        [, $merchant] = $this->merchantWithUser(Roles::MERCHANT_OWNER);
        $this->withToken($this->tokenFor($merchant))->getJson('/api/v1/admin/activity')->assertForbidden();
    }
}
