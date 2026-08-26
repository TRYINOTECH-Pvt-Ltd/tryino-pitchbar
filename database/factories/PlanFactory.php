<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Free', 'Standard', 'Pro', 'Custom']);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'monthly_conversations' => fake()->randomElement([100, 500, 3000, 0]),
            'price_cents' => fake()->randomElement([0, 4900, 24900, 0]),
            'stripe_price_id' => null,
            'features' => [],
            'is_active' => true,
        ];
    }
}
