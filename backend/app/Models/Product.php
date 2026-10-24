<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentTenant;
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
        'unit',
        'offer_percent',
        'offer_starts_on',
        'offer_ends_on',
        'duration_mins',
        'is_active',
        'is_available',
        'staff_check',
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
            'offer_starts_on' => 'date:Y-m-d',
            'offer_ends_on' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'staff_check' => 'boolean',
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
    /**
     * The offer running today in the shop's timezone (percent off), or null.
     */
    public function activeOfferPercent(): ?int
    {
        $percent = (int) $this->offer_percent;
        if ($percent < 1 || $percent > 90) {
            return null;
        }

        $merchant = app(CurrentTenant::class)->get();
        $today = now()->setTimezone($merchant?->timezone ?: config('app.timezone'))->toDateString();
        if (($this->offer_starts_on && $this->offer_starts_on->toDateString() > $today)
            || ($this->offer_ends_on && $this->offer_ends_on->toDateString() < $today)) {
            return null;
        }

        return $percent;
    }

    /** A base price (product or size) after today's offer. */
    public function priceAfterOffer(float $base): float
    {
        $percent = $this->activeOfferPercent();

        return $percent ? round($base * (100 - $percent) / 100, 2) : $base;
    }

    public function isOrderable(): bool
    {
        // A 0.00 price is a typo (or a bad lira conversion), never a free item.
        if (! $this->is_active || ! $this->is_available || (float) $this->price < 0.01) {
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
