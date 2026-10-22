<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop and its owner login are created in one transaction. If the owner step fails (here: the
 * email is already taken), the shop must not be left behind half-created.
 */
class ProvisioningRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_failed_owner_step_leaves_no_shop_behind(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $merchantsBefore = Merchant::query()->withoutGlobalScopes()->count();

        $this->expectException(QueryException::class);
        try {
            app(TenantProvisioningService::class)->provision(
                ['name' => 'Ghost Shop', 'business_type' => 'Restaurant / Café'],
                ['name' => 'Owner', 'email' => 'taken@example.com', 'password' => 'Secret2026!'],
            );
        } finally {
            $this->assertSame($merchantsBefore, Merchant::query()->withoutGlobalScopes()->count(), 'no shop was created');
            $this->assertDatabaseMissing('merchants', ['name' => 'Ghost Shop']);
            $this->assertSame(0, User::query()->where('email', 'taken@example.com')->where('name', 'Owner')->count(), 'no second login was created');
        }
    }

    public function test_a_successful_provision_creates_the_shop_and_its_owner_together(): void
    {
        $result = app(TenantProvisioningService::class)->provision(
            ['name' => 'Whole Shop', 'business_type' => 'Restaurant / Café'],
            ['name' => 'Owner', 'email' => 'fresh@example.com', 'password' => 'Secret2026!'],
        );

        $this->assertSame($result['merchant']->id, $result['user']->merchant_id);
        $this->assertTrue($result['user']->hasRole(Roles::MERCHANT_OWNER));
        $this->assertDatabaseHas('merchants', ['name' => 'Whole Shop']);
    }
}
