<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'action' => $this->faker->randomElement(['agent.updated', 'workflow.created', 'member.invited', 'plan.changed']),
            'entity_type' => 'agent',
            'entity_id' => (string) $this->faker->uuid(),
            'before' => [],
            'after' => ['status' => 'updated'],
            'ip' => $this->faker->ipv4(),
            'ua' => $this->faker->userAgent(),
            'created_at' => now(),
        ];
    }
}
