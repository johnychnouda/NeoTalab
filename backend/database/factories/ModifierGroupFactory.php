<?php

namespace Database\Factories;

use App\Models\ModifierGroup;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    protected $model = ModifierGroup::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'merchant_id' => fn (array $attrs) => Product::withoutGlobalScopes()
                ->findOrFail($attrs['product_id'])->merchant_id,
            'name' => ucfirst(fake()->word()),
            'required' => false,
            'min_select' => 0,
            'max_select' => 1,
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
