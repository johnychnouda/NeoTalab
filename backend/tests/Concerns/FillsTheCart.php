<?php

namespace Tests\Concerns;

use App\Models\Merchant;
use App\Models\Product;
use App\Services\AI\DTO\CartAction;
use App\Services\CartService;
use App\Services\ConversationService;
use App\Support\CurrentTenant;

/**
 * Puts an item in a customer's cart the way the assistant does (through CartService), for tests that
 * are about what happens to the cart, stock and order, not about how the customer asked.
 */
trait FillsTheCart
{
    /**
     * @param  list<string>  $modifierIds
     * @return array{applied: int, rejected: array<int, array<string, mixed>>}
     */
    protected function putInCart(Merchant $merchant, string $from, Product $product, int|float $quantity = 1, ?string $variantId = null, array $modifierIds = []): array
    {
        app(CurrentTenant::class)->set($merchant);
        $cart = app(ConversationService::class)->startOrResume(['phone' => '+'.ltrim($from, '+')], 'whatsapp')->cart;

        return app(CartService::class)->applyActions($cart, [
            new CartAction(action: 'add', productId: $product->id, variantId: $variantId, quantity: $quantity, modifierIds: $modifierIds),
        ]);
    }
}
