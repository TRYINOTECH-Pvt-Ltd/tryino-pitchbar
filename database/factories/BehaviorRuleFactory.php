<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\BehaviorRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BehaviorRule>
 */
class BehaviorRuleFactory extends Factory
{
    protected $model = BehaviorRule::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'name' => fake()->words(2, true),
            'kind' => fake()->randomElement(['exit_intent', 'idle', 'scroll', 'time', 'returning', 'utm', 'abandoned_cart']),
            'conditions' => [],
            'action' => ['kind' => 'open_with_message', 'message' => fake()->sentence()],
            'cta_rule_id' => null,
            'enabled' => true,
            'priority' => 0,
        ];
    }
}
