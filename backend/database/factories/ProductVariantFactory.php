<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            // Keep the variant in the same tenant as its (possibly factory-created) product.
            'merchant_id' => fn (array $attrs) => Product::withoutGlobalScopes()
                ->findOrFail($attrs['product_id'])->merchant_id,
            'name' => fake()->randomElement(['Small', 'Medium', 'Large', 'XL']),
            'price' => null,
            'is_active' => true,
            'is_available' => true,
            'sort_order' => 0,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn () => [
            'product_id' => $product->id,
            'merchant_id' => $product->merchant_id,
        ]);
    }
}
