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
}
