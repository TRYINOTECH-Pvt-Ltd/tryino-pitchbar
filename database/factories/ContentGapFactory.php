<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\ContentGap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentGap>
 */
class ContentGapFactory extends Factory
{
    protected $model = ContentGap::class;

    public function definition(): array
    {
        $question = fake()->sentence().'?';

        return [
            'agent_id' => Agent::factory(),
            'question' => $question,
            'question_hash' => hash('sha256', strtolower($question)),
            'occurrences' => fake()->numberBetween(1, 100),
            'last_seen_at' => now(),
            'status' => 'open',
        ];
    }
}
