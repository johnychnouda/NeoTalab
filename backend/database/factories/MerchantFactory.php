<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'business_type' => fake()->randomElement(['restaurant', 'pharmacy', 'retail', 'grocery', 'services']),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'locale' => 'en',
            'currency' => 'USD',
            'timezone' => 'Asia/Beirut',
            'plan' => 'basic',
            'billing_cycle' => 'monthly',
            'trial_ends_at' => now()->addDays(7),
        ];
    }
}
