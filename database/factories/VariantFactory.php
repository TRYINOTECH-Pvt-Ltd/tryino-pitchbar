<?php

namespace Database\Factories;

use App\Models\Experiment;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    protected $model = Variant::class;

    public function definition(): array
    {
        return [
            'experiment_id' => Experiment::factory(),
            'name' => fake()->randomElement(['control', 'treatment']),
            'config' => [],
            'weight' => 50,
        ];
    }
}
