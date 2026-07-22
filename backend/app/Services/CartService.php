<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Services\AI\CatalogResolver;
use App\Services\AI\DTO\CartAction;
use App\Services\AI\DTO\CartLineSnapshot;
use App\Support\Conversation\CartStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persistent cart engine: resolves AI cart actions against the tenant catalog,
 * rejects foreign/invalid items, and recalculates subtotals.
 */
class CartService
{
    public function __construct(protected CatalogResolver $catalog) {}

    public function createForConversation(string $conversationId): Cart
    {
        return Cart::create([
            'conversation_id' => $conversationId,
            'status' => CartStatus::Open,
            'subtotal' => 0,
        ]);
    }

    /**
     * @param  array<int, CartAction>  $actions
     * @return array{applied: int, rejected: array<int, array{action:string,reason:string}>}
     */
    public function applyActions(Cart $cart, array $actions): array
    {
        $applied = 0;
        $rejected = [];

        DB::transaction(function () use ($cart, $actions, &$applied, &$rejected) {
            foreach ($actions as $action) {
                try {
                    match ($action->action) {
                        'add' => $this->addItem($cart, $action),
                        'update' => $this->updateItem($cart, $action),
                        'remove' => $this->removeItem($cart, $action),
                        'clear' => $this->clearCart($cart),
                        default => throw new InvalidArgumentException("Unknown cart action: {$action->action}"),
                    };
                    $applied++;
                } catch (InvalidArgumentException $e) {
                    $rejected[] = ['action' => $action->action, 'reason' => $e->getMessage()];
                }
            }

            $this->recalculateSubtotal($cart);
        });

        return ['applied' => $applied, 'rejected' => $rejected];
    }

    /**
     * @return Collection<int, CartLineSnapshot>
     */
    public function snapshotLines(Cart $cart): Collection
    {
        return $cart->items()->with(['product', 'variant'])->get()->map(
            fn (CartItem $item) => new CartLineSnapshot(
                id: $item->id,
                productId: $item->product_id,
                productName: $item->product?->name,
                variantId: $item->variant_id,
                quantity: $item->quantity,
                unitPrice: number_format((float) $item->unit_price, 2, '.', ''),
                modifierIds: $item->modifier_ids ?? [],
                lineTotal: number_format((float) $item->line_total, 2, '.', ''),
            )
        );
    }

    public function loadWithItems(Cart $cart): Cart
    {
        return $cart->load(['items.product', 'items.variant']);
    }

    private function addItem(Cart $cart, CartAction $action): void
    {
        if (! $action->productId) {
            throw new InvalidArgumentException('add requires product_id.');
        }

        $product = $this->catalog->resolveProduct($action->productId);
        if (! $product) {
            throw new InvalidArgumentException('Product not found in this catalog.');
        }

        if (! $product->isOrderable()) {
            throw new InvalidArgumentException('Product is not orderable.');
        }

        $variant = $this->catalog->resolveVariant($product, $action->variantId);
        if ($action->variantId && ! $variant) {
            throw new InvalidArgumentException('Variant not found for this product.');
        }

        $modifiers = $this->catalog->resolveModifiers($product, $action->modifierIds);
        if (count($action->modifierIds) !== $modifiers->count()) {
            throw new InvalidArgumentException('One or more modifiers are invalid for this product.');
        }

        $qty = max(1, $action->quantity);
        $unitPrice = $this->catalog->calculateUnitPrice($product, $variant, $modifiers);
        $lineTotal = number_format((float) $unitPrice * $qty, 2, '.', '');

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'modifier_ids' => $modifiers->pluck('id')->values()->all(),
            'line_total' => $lineTotal,
            'notes' => $action->notes,
        ]);
    }

    private function updateItem(Cart $cart, CartAction $action): void
    {
        if (! $action->cartItemId) {
            throw new InvalidArgumentException('update requires cart_item_id.');
        }

        $item = CartItem::where('cart_id', $cart->id)->find($action->cartItemId);
        if (! $item) {
            throw new InvalidArgumentException('Cart item not found.');
        }

        $qty = max(1, $action->quantity);
        $item->update([
            'quantity' => $qty,
            'line_total' => number_format((float) $item->unit_price * $qty, 2, '.', ''),
        ]);
    }

    private function removeItem(Cart $cart, CartAction $action): void
    {
        if (! $action->cartItemId) {
            throw new InvalidArgumentException('remove requires cart_item_id.');
        }

        $deleted = CartItem::where('cart_id', $cart->id)->where('id', $action->cartItemId)->delete();
        if (! $deleted) {
            throw new InvalidArgumentException('Cart item not found.');
        }
    }

    private function clearCart(Cart $cart): void
    {
        CartItem::where('cart_id', $cart->id)->delete();
    }

    private function recalculateSubtotal(Cart $cart): void
    {
        $subtotal = CartItem::where('cart_id', $cart->id)->sum('line_total');
        $cart->update(['subtotal' => number_format((float) $subtotal, 2, '.', '')]);
    }
}
