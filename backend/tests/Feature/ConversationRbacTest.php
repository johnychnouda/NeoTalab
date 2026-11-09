<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ConversationRbacTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_platform_admin_without_tenant_cannot_start_tenant_conversation(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->tokenFor($admin);

        // Platform admins are not in merchant roles, route is forbidden
        $this->withToken($token)->postJson('/api/v1/conversations', [
            'phone' => '+96170999998',
        ])->assertForbidden();
    }

    public function test_blocked_customer_cannot_start_conversation(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        Customer::factory()->forMerchant($merchant)->blocked()->create([
            'phone' => '+96170101010',
        ]);
        $token = $this->tokenFor($owner);

        $this->withToken($token)->postJson('/api/v1/conversations', [
            'phone' => '+96170101010',
        ])->assertStatus(422)->assertJsonPath('message', 'Customer is blocked.');
    }
}
