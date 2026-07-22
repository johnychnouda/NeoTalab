<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'category_id',
        'name',
        'name_ar',
        'name_fr',
        'description',
        'sku',
        'image_url',
        'price',
        'is_active',
        'is_available',
        'track_inventory',
        'stock_quantity',
        'low_stock_threshold',
        'prep_time_mins',
        'sort_order',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'track_inventory' => 'boolean',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'prep_time_mins' => 'integer',
            'sort_order' => 'integer',
            'attributes' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function modifierGroups(): HasMany
    {
        return $this->hasMany(ModifierGroup::class);
    }

    /**
     * Orderable right now: listed, not sold out, and (when counted) in stock.
     */
    public function isOrderable(): bool
    {
        if (! $this->is_active || ! $this->is_available) {
            return false;
        }

        if ($this->track_inventory) {
            return ($this->stock_quantity ?? 0) > 0;
        }

        return true;
    }

    public function isLowStock(): bool
    {
        return $this->track_inventory
            && $this->low_stock_threshold !== null
            && ($this->stock_quantity ?? 0) <= $this->low_stock_threshold;
    }
}
