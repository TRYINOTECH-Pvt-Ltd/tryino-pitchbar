<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Ticket;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'agent_id' => null,
            'conversation_id' => null,
            'subject' => fake()->sentence(6),
            'body' => fake()->paragraph(3),
            'status' => Ticket::STATUS_OPEN,
            'priority' => Ticket::PRIORITY_NORMAL,
            'assigned_to_user_id' => null,
            'metadata' => [],
        ];
    }

    public function withAgent(): static
    {
        return $this->state(fn (array $attrs) => [
            'agent_id' => Agent::factory()->create(['workspace_id' => $attrs['workspace_id']])->id,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => Ticket::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }
}
