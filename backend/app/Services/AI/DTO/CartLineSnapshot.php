<?php

namespace App\Services\AI\DTO;

/**
 * Snapshot of a cart line passed to the AI provider.
 */
final readonly class CartLineSnapshot
{
    /**
     * @param  array<int, string>  $modifierIds
     */
    public function __construct(
        public string $id,
        public string $productId,
        public ?string $productName,
        public ?string $variantId,
        public int $quantity,
        public string $unitPrice,
        public array $modifierIds,
        public string $lineTotal,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'variant_id' => $this->variantId,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'modifier_ids' => $this->modifierIds,
            'line_total' => $this->lineTotal,
        ];
    }
}
