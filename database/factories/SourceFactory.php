<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'type' => 'url',
            'status' => 'pending',
            'config' => ['url' => fake()->url()],
            'last_synced_at' => null,
            'error' => null,
        ];
    }
}
