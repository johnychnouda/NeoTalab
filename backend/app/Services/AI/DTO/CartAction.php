<?php

namespace App\Services\AI\DTO;

/**
 * One cart mutation the AI wants applied. Resolved against the tenant catalog
 * by CartService — IDs may be rejected if they don't belong to this merchant.
 */
final readonly class CartAction
{
    /**
     * @param  array<int, string>  $modifierIds
     */
    public function __construct(
        public string $action, // add, update, remove, clear
        public ?string $productId = null,
        public ?string $variantId = null,
        public ?string $cartItemId = null,
        public int $quantity = 1,
        public array $modifierIds = [],
        public ?string $notes = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            action: (string) ($data['action'] ?? 'add'),
            productId: $data['product_id'] ?? null,
            variantId: $data['variant_id'] ?? null,
            cartItemId: $data['cart_item_id'] ?? null,
            quantity: (int) ($data['quantity'] ?? 1),
            modifierIds: array_values($data['modifier_ids'] ?? []),
            notes: $data['notes'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'action' => $this->action,
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'cart_item_id' => $this->cartItemId,
            'quantity' => $this->quantity,
            'modifier_ids' => $this->modifierIds,
            'notes' => $this->notes,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
