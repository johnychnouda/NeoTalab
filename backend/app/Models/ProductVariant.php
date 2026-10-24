<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'product_id',
        'name',
        'name_ar',
        'name_fr',
        'sku',
        'price',
        'is_active',
        'is_available',
        'stock_quantity',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'stock_quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Effective price: the variant's own price, or the parent product's when not overridden.
     */
    public function effectivePrice(): string
    {
        return $this->price ?? $this->product->price;
    }

    /**
     * Whether customers can pick this size. A 0.00 price is a forgotten price box, never a free
     * size (same rule as Product::isOrderable); an empty price inherits the product's.
     */
    /**
     * A size's own stock, when the item tracks stock and the size has a number, also limits that
     * size (the item's stock stays the total).
     */
    public function hasStockFor(Product $product, int $quantity): bool
    {
        return ! $product->track_inventory || $this->stock_quantity === null || $this->stock_quantity >= $quantity;
    }

    public function isOffered(): bool
    {
        return ($this->is_active ?? true) && ($this->is_available ?? true)
            && ($this->price === null || (float) $this->price >= 0.01);
    }
}
