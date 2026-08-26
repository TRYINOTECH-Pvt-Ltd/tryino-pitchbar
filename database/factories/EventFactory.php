<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'agent_id' => null,
            'conversation_id' => null,
            'kind' => fake()->randomElement(['conversation.completed', 'lead.captured', 'cta.clicked']),
            'payload' => [],
            'created_at' => now(),
        ];
    }
}
