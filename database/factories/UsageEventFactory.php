<?php

namespace Database\Factories;

use App\Models\UsageEvent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'kind' => 'conversation',
            'quantity' => 1,
            'meta' => [],
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }
}
