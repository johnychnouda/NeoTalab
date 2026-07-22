<?php

namespace Tests\Feature;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AI\DTO\CartAction;
use App\Services\CartService;
use App\Services\ConversationService;
use App\Support\CurrentTenant;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_can_start_conversation_and_process_turns_with_fake_driver(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        Product::factory()->forMerchant($merchant)->create(['name' => 'Shawarma Wrap', 'price' => 5.00]);
        Product::factory()->forMerchant($merchant)->create(['name' => 'Fresh Juice', 'price' => 3.50]);
        $token = $this->tokenFor($owner);

        $conversationId = $this->withToken($token)->postJson('/api/v1/conversations', [
            'phone' => '+96170123456',
            'name' => 'Ali',
            'locale' => 'en',
        ])->assertCreated()
            ->assertJsonPath('data.customer.phone', '+96170123456')
            ->assertJsonPath('data.cart.subtotal', '0.00')
            ->json('data.id');

        $this->withToken($token)->postJson("/api/v1/conversations/{$conversationId}/turns", [
            'message' => 'hello',
        ])->assertOk()
            ->assertJsonPath('data.ai.intent', 'greeting')
            ->assertJsonPath('data.ai.recommended_action', 'show_menu');

        $this->withToken($token)->postJson("/api/v1/conversations/{$conversationId}/turns", [
            'message' => 'add 2 shawarma wrap',
        ])->assertOk()
            ->assertJsonPath('data.ai.intent', 'modify_cart')
            ->assertJsonPath('data.cart.subtotal', '10.00')
            ->assertJsonCount(1, 'data.cart.items')
            ->assertJsonPath('data.cart.items.0.quantity', 2);

        $this->withToken($token)->getJson("/api/v1/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.cart.subtotal', '10.00');
    }

    public function test_menu_inquiry_lists_available_products(): void
    {
        [$merchant, $staff] = $this->merchantWithUser(Roles::MERCHANT_STAFF);
        Product::factory()->forMerchant($merchant)->create(['name' => 'Labneh Plate']);
        $token = $this->tokenFor($staff);

        $id = $this->withToken($token)->postJson('/api/v1/conversations', [
            'phone' => '+96170999999',
        ])->json('data.id');

        $this->withToken($token)->postJson("/api/v1/conversations/{$id}/turns", [
            'message' => 'show menu',
        ])->assertOk()
            ->assertJsonPath('data.ai.intent', 'inquiry')
            ->assertJsonPath('data.ai.entities.available_products.0', 'Labneh Plate');
    }

    public function test_cart_rejects_unknown_product_from_fake_driver(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        Product::factory()->forMerchant($merchant)->create(['name' => 'Pizza']);
        $token = $this->tokenFor($owner);

        $id = $this->withToken($token)->postJson('/api/v1/conversations', [
            'phone' => '+96170111111',
        ])->json('data.id');

        $this->withToken($token)->postJson("/api/v1/conversations/{$id}/turns", [
            'message' => 'add 1 sushi roll',
        ])->assertOk()
            ->assertJsonPath('data.ai.missing_fields.0', 'product')
            ->assertJsonPath('data.cart.subtotal', '0.00');
    }

    public function test_cart_service_resolves_variants_and_modifiers(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $product = Product::factory()->forMerchant($merchant)->create(['name' => 'Coffee', 'price' => 4.00]);
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'name' => 'Large',
            'price' => 5.50,
        ]);
        $group = ModifierGroup::factory()->forProduct($product)->create();
        $modifier = Modifier::factory()->forGroup($group)->create([
            'name' => 'Extra shot',
            'price_delta' => 1.00,
        ]);

        $cartService = app(CartService::class);
        app(CurrentTenant::class)->set($merchant);

        $conversation = app(ConversationService::class)->startOrResume([
            'phone' => '+96170222222',
        ]);

        $cart = $conversation->cart;
        $result = $cartService->applyActions($cart, [
            new CartAction(
                action: 'add',
                productId: $product->id,
                variantId: $variant->id,
                modifierIds: [$modifier->id],
                quantity: 1,
            ),
        ]);

        $this->assertSame(1, $result['applied']);
        $this->assertSame([], $result['rejected']);

        $cart->refresh()->load('items');
        $this->assertSame('6.50', number_format((float) $cart->subtotal, 2, '.', ''));
        $this->assertSame('6.50', number_format((float) $cart->items->first()->line_total, 2, '.', ''));
    }

    public function test_cart_rejects_foreign_product_id(): void
    {
        [$merchantA] = $this->merchantWithUser();
        [$merchantB] = $this->merchantWithUser();
        $foreignProduct = Product::factory()->forMerchant($merchantB)->create();

        app(CurrentTenant::class)->set($merchantA);

        $conversation = app(ConversationService::class)->startOrResume([
            'phone' => '+96170333333',
        ]);

        $result = app(CartService::class)->applyActions($conversation->cart, [
            new CartAction(action: 'add', productId: $foreignProduct->id, quantity: 1),
        ]);

        $this->assertSame(0, $result['applied']);
        $this->assertCount(1, $result['rejected']);
        $this->assertStringContainsString('not found', $result['rejected'][0]['reason']);
    }

    public function test_clear_cart_via_turn(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        Product::factory()->forMerchant($merchant)->create(['name' => 'Falafel', 'price' => 2.00]);
        $token = $this->tokenFor($owner);

        $id = $this->withToken($token)->postJson('/api/v1/conversations', [
            'phone' => '+96170444444',
        ])->json('data.id');

        $this->withToken($token)->postJson("/api/v1/conversations/{$id}/turns", [
            'message' => 'add 3 falafel',
        ])->assertJsonPath('data.cart.subtotal', '6.00');

        $this->withToken($token)->postJson("/api/v1/conversations/{$id}/turns", [
            'message' => 'clear cart',
        ])->assertJsonPath('data.cart.subtotal', '0.00')
            ->assertJsonCount(0, 'data.cart.items');
    }

    public function test_resume_returns_existing_active_conversation(): void
    {
        [$merchant, $owner] = $this->merchantWithUser();
        $token = $this->tokenFor($owner);

        $first = $this->withToken($token)->postJson('/api/v1/conversations', [
            'phone' => '+96170555555',
        ])->json('data.id');

        $second = $this->withToken($token)->postJson('/api/v1/conversations', [
            'phone' => '+96170555555',
        ])->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
    }
}
