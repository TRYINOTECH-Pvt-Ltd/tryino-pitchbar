<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\UsageLog;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageLog>
 */
class UsageLogFactory extends Factory
{
    protected $model = UsageLog::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'agent_id' => Agent::factory(),
            'conversation_id' => null,
            'message_id' => null,
            'provider' => $this->faker->randomElement(['cloudflare', 'openai', 'openrouter']),
            'model' => $this->faker->randomElement([
                '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
                '@cf/baai/bge-base-en-v1.5',
                'gpt-4o-mini',
            ]),
            'purpose' => $this->faker->randomElement(['chat', 'embed', 'rerank']),
            'tokens_in' => $this->faker->numberBetween(50, 2000),
            'tokens_out' => $this->faker->numberBetween(0, 800),
            'cost_usd_micro' => $this->faker->numberBetween(1, 5000),
            'latency_ms' => $this->faker->numberBetween(80, 1500),
            'created_at' => now(),
        ];
    }
}
