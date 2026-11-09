<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_shop_cannot_rewrite_its_settings_through_the_profile_update(): void
    {
        [$merchant, $user] = $this->merchantWithUser();
        $merchant->update(['settings' => ['bot' => ['phone_id' => 'mine']]]);

        $this->withToken($this->tokenFor($user))->patchJson('/api/v1/merchant', [
            'name' => 'New name',
            'settings' => ['bot' => ['phone_id' => 'another-shops-number']],
        ])->assertOk();

        $this->assertSame('New name', $merchant->fresh()->name);
        $this->assertSame('mine', $merchant->fresh()->settings['bot']['phone_id']);
    }
}
