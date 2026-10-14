<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'category_id' => null,
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'price' => fake()->randomFloat(2, 1, 100),
            'is_active' => true,
            'is_available' => true,
            'track_inventory' => false,
            'sort_order' => 0,
        ];
    }

    public function forMerchant(Merchant $merchant): static
    {
        return $this->state(fn () => ['merchant_id' => $merchant->id]);
    }

    public function tracked(int $stock, ?int $threshold = null): static
    {
        return $this->state(fn () => [
            'track_inventory' => true,
            'stock_quantity' => $stock,
            'low_stock_threshold' => $threshold,
        ]);
    }
}
