<?php

namespace Database\Factories;

use App\Models\WidgetEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WidgetEvent>
 */
class WidgetEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => (string) Str::uuid(),
            'agent_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'type' => 'stream_failed',
            'severity' => WidgetEvent::SEVERITY_ERROR,
            'provider' => 'cloudflare',
            'message' => $this->faker->sentence(),
            'context' => ['exception' => 'OpenAiTimeoutException'],
            'occurred_at' => now(),
            'resolved_at' => null,
            'resolved_by' => null,
        ];
    }

    /**
     * A provider-failover warning (a fallback provider saved the turn).
     */
    public function failover(): static
    {
        return $this->state(fn (): array => [
            'type' => 'provider_failover',
            'severity' => WidgetEvent::SEVERITY_WARNING,
        ]);
    }

    /**
     * Already triaged.
     */
    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'resolved_at' => now(),
            'resolved_by' => 1,
        ]);
    }
}
