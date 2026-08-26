<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\CuratedAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuratedAnswer>
 */
class CuratedAnswerFactory extends Factory
{
    protected $model = CuratedAnswer::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'question_pattern' => fake()->sentence().'?',
            'answer' => fake()->paragraph(),
            'priority' => fake()->numberBetween(0, 100),
            'conditions' => [],
            'lang' => 'en',
            'enabled' => true,
        ];
    }
}
