<?php

namespace Database\Factories;

use App\Models\IntegrationConnection;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationConnection>
 */
class IntegrationConnectionFactory extends Factory
{
    protected $model = IntegrationConnection::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'kind' => fake()->randomElement(['slack', 'webhook', 'hubspot']),
            'credentials_encrypted' => ['token' => fake()->sha256()],
            'config' => [],
            'status' => 'active',
            'last_sync_at' => null,
        ];
    }
}
