<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->company().' Agent',
            'language_default' => 'en',
            'persona' => [
                'name' => fake()->firstName(),
                'tone' => 'friendly',
            ],
            'theme' => [
                'primary' => '#111827',
                'accent' => '#10b981',
                'radius' => 12,
            ],
            'allowed_origins' => ['https://example.com'],
            'system_prompt' => 'Be helpful.',
            'guardrails' => [
                'avoid' => [],
                'max_chars' => 2500,
            ],
            'confidence_threshold' => (float) config('services.rag.confidence_threshold', 0.5),
            'is_published' => false,
            'auto_index_visited_pages' => true,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['is_published' => true]);
    }
}
