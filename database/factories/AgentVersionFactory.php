<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentVersion>
 */
class AgentVersionFactory extends Factory
{
    protected $model = AgentVersion::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'snapshot' => ['name' => fake()->company()],
            'created_by' => null,
        ];
    }
}
