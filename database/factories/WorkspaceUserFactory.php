<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceUser>
 */
class WorkspaceUserFactory extends Factory
{
    protected $model = WorkspaceUser::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'role' => fake()->randomElement(['owner', 'admin', 'editor', 'viewer']),
            'invited_at' => now(),
            'accepted_at' => now(),
        ];
    }
}
