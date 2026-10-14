<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'phone' => '+961'.fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'locale' => fake()->randomElement(['en', 'ar', 'fr']),
            'is_blocked' => false,
        ];
    }

    public function forMerchant(Merchant $merchant): static
    {
        return $this->state(fn () => ['merchant_id' => $merchant->id]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['is_blocked' => true]);
    }
}
