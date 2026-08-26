<?php

namespace Database\Factories;

use App\Models\WebhookSubscription;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookSubscription>
 */
class WebhookSubscriptionFactory extends Factory
{
    protected $model = WebhookSubscription::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'url' => fake()->url(),
            'secret' => Str::random(64),
            'events' => ['lead.captured'],
            'enabled' => true,
        ];
    }
}
