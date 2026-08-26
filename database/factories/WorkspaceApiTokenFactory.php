<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceApiToken>
 */
class WorkspaceApiTokenFactory extends Factory
{
    protected $model = WorkspaceApiToken::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by_user_id' => null,
            'name' => fake()->words(2, true).' token',
            'token_hash' => hash('sha256', Str::random(64)),
            'abilities' => ['wp:integration'],
            'last_used_at' => null,
            'revoked_at' => null,
            'shopper_signing_secret' => Str::random(48),
        ];
    }

    public function revoked(): self
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
