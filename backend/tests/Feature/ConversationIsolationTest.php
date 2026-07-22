<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Product;
use App\Services\AI\DTO\CartAction;
use App\Services\CartService;
use App\Services\ConversationService;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * M3 acceptance gate: conversations, customers, and carts are invisible and untouchable
 * across tenants, including id-injection on turns.
 */
class ConversationIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_cannot_read_foreign_conversation(): void
    {
        [, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        $customerB = Customer::factory()->forMerchant($merchantB)->create();
        $conversationB = Conversation::factory()->forCustomer($customerB)->create();
        $token = $this->tokenFor($ownerA);

        $this->withToken($token)->getJson("/api/v1/conversations/{$conversationB->id}")->assertNotFound();
    }

    public function test_cannot_post_turn_to_foreign_conversation(): void
    {
        [, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        Product::factory()->forMerchant($merchantB)->create(['name' => 'Secret Item']);
        $customerB = Customer::factory()->forMerchant($merchantB)->create();
        $conversationB = Conversation::factory()->forCustomer($customerB)->create();
        $token = $this->tokenFor($ownerA);

        $this->withToken($token)->postJson("/api/v1/conversations/{$conversationB->id}/turns", [
            'message' => 'add 1 secret item',
        ])->assertNotFound();
    }

    public function test_cannot_inject_foreign_product_via_direct_cart_action(): void
    {
        [$merchantA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        $productB = Product::factory()->forMerchant($merchantB)->create(['name' => 'Foreign', 'price' => 9.99]);

        app(CurrentTenant::class)->set($merchantA);
        $conversation = app(ConversationService::class)->startOrResume([
            'phone' => '+96170666666',
        ]);

        $result = app(CartService::class)->applyActions($conversation->cart, [
            new CartAction(action: 'add', productId: $productB->id, quantity: 1),
        ]);

        $this->assertSame(0, $result['applied']);
        $this->assertDatabaseMissing('cart_items', ['product_id' => $productB->id]);
    }

    public function test_customers_are_isolated_per_tenant(): void
    {
        [$merchantA, $ownerA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        Customer::factory()->forMerchant($merchantB)->create(['phone' => '+96170777777']);

        $token = $this->tokenFor($ownerA);

        // Same phone in tenant A is a distinct customer row
        $this->withToken($token)->postJson('/api/v1/conversations', [
            'phone' => '+96170777777',
        ])->assertCreated();

        $this->assertDatabaseHas('customers', [
            'merchant_id' => $merchantA->id,
            'phone' => '+96170777777',
        ]);
        $this->assertDatabaseHas('customers', [
            'merchant_id' => $merchantB->id,
            'phone' => '+96170777777',
        ]);
    }
}
