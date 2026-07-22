<?php

namespace Database\Factories;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Modifier>
 */
class ModifierFactory extends Factory
{
    protected $model = Modifier::class;

    public function definition(): array
    {
        return [
            'modifier_group_id' => ModifierGroup::factory(),
            'merchant_id' => fn (array $attrs) => ModifierGroup::withoutGlobalScopes()
                ->findOrFail($attrs['modifier_group_id'])->merchant_id,
            'name' => ucfirst(fake()->word()),
            'price_delta' => fake()->randomFloat(2, 0, 5),
            'is_active' => true,
            'is_available' => true,
            'sort_order' => 0,
        ];
    }

    public function forGroup(ModifierGroup $group): static
    {
        return $this->state(fn () => [
            'modifier_group_id' => $group->id,
            'merchant_id' => $group->merchant_id,
        ]);
    }
}
