<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\CtaRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CtaRule>
 */
class CtaRuleFactory extends Factory
{
    protected $model = CtaRule::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'name' => fake()->words(2, true),
            'label' => fake()->randomElement(['Buy now', 'Book a demo', 'Sign up']),
            'kind' => fake()->randomElement(['buy', 'demo', 'signup', 'book', 'link']),
            'conditions' => [],
            'target' => ['url' => fake()->url()],
            'enabled' => true,
            'priority' => 0,
        ];
    }
}
