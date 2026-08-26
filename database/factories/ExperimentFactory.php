<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Experiment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Experiment>
 */
class ExperimentFactory extends Factory
{
    protected $model = Experiment::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'name' => fake()->words(3, true),
            'kind' => fake()->randomElement(['persona', 'cta', 'trigger']),
            'status' => 'draft',
            'traffic_split' => ['a' => 50, 'b' => 50],
            'started_at' => null,
            'stopped_at' => null,
        ];
    }
}
