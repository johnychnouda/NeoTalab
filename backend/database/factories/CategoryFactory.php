<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function forMerchant(Merchant $merchant): static
    {
        return $this->state(fn () => ['merchant_id' => $merchant->id]);
    }
}
