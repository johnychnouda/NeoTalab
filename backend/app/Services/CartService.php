<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductVariant;
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
    public const MAX_LINE_QUANTITY = 99;

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
                    $rejected[] = ['action' => $action->action, 'reason' => $e->getMessage(), 'product_id' => $action->productId];
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

        if ($product->duration_mins) {
            throw new InvalidArgumentException('Services are booked as appointments, not added to the cart.');
        }

        $variant = $this->catalog->resolveVariant($product, $action->variantId);
        if ($action->variantId && ! $variant) {
            throw new InvalidArgumentException('Variant not found for this product.');
        }
        if ($variant && ! $variant->isOffered()) {
            throw new InvalidArgumentException('This size is not available.');
        }

        $modifiers = $this->catalog->resolveModifiers($product, $action->modifierIds);
        if (count(array_unique($action->modifierIds)) !== count($action->modifierIds) || count($action->modifierIds) !== $modifiers->count()) {
            throw new InvalidArgumentException('One or more modifiers are invalid for this product.');
        }

        $this->requireChoices($product, $variant, $modifiers);

        $qty = max(1, min(self::MAX_LINE_QUANTITY, $action->quantity));
        $unitPrice = $this->catalog->calculateUnitPrice($product, $variant, $modifiers);
        if ((float) $unitPrice < 0.01) {
            throw new InvalidArgumentException('Product is not orderable.'); // never a free or negative line
        }
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

    /**
     * Same rules as the chat menu (ChatMenu::nextStep): a product with sizes needs one, and a
     * required option group needs its minimum, otherwise the line would be priced wrong.
     *
     * @param  Collection<int, Modifier>  $modifiers
     */
    private function requireChoices(Product $product, ?ProductVariant $variant, Collection $modifiers): void
    {
        $variants = $product->variants->filter(fn ($v) => $v->isOffered());
        if ($variants->isNotEmpty() && ! $variant) {
            throw new InvalidArgumentException('This product has sizes: ask the customer which one (variant_id).');
        }

        $chosen = $modifiers->pluck('id')->all();
        foreach ($product->modifierGroups as $group) {
            // "Choose 1 sauce" means one: more would be a line the shop cannot make.
            // A group saved with a minimum above its maximum allows the minimum.
            $max = max((int) $group->max_select, (int) $group->min_select);
            $inGroup = count(array_intersect($chosen, $group->modifiers->pluck('id')->all()));
            if ($max > 0 && $inGroup > $max) {
                throw new InvalidArgumentException("Option \"{$group->name}\" allows at most {$max}: ask the customer to choose.");
            }

            $offered = $group->modifiers->filter(fn ($m) => ($m->is_active ?? true) && ($m->is_available ?? true));
            if (! $group->required || $offered->isEmpty()) {
                continue;
            }
            $picked = count(array_intersect($chosen, $group->modifiers->pluck('id')->all()));
            if ($picked < max(1, (int) $group->min_select)) {
                throw new InvalidArgumentException("Option \"{$group->name}\" is required: ask the customer to choose.");
            }
        }
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

        $qty = max(1, min(self::MAX_LINE_QUANTITY, $action->quantity));
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

    /**
     * Today's prices: a line keeps the quantity and choices the customer made, but its price is read
     * again from the menu (item, size, extras, offer), so a price the shop corrected applies at checkout.
     * Lines whose item is gone keep their price; checkout refuses them as unavailable.
     */
    public function refreshPrices(Cart $cart): void
    {
        foreach (CartItem::where('cart_id', $cart->id)->get() as $item) {
            $product = Product::with(['variants', 'modifierGroups.modifiers'])->find($item->product_id);
            if (! $product) {
                continue;
            }
            $variant = $this->catalog->resolveVariant($product, $item->variant_id);
            $modifiers = $this->catalog->resolveModifiers($product, $item->modifier_ids ?? []);
            $unit = $this->catalog->calculateUnitPrice($product, $variant, $modifiers);
            $line = number_format((float) $unit * $item->quantity, 2, '.', '');
            if ($unit !== number_format((float) $item->unit_price, 2, '.', '') || $line !== number_format((float) $item->line_total, 2, '.', '')) {
                $item->update(['unit_price' => $unit, 'line_total' => $line]);
            }
        }

        $this->recalculateSubtotal($cart);
    }

    private function recalculateSubtotal(Cart $cart): void
    {
        $subtotal = CartItem::where('cart_id', $cart->id)->sum('line_total');
        $cart->update(['subtotal' => number_format((float) $subtotal, 2, '.', '')]);
    }
}
