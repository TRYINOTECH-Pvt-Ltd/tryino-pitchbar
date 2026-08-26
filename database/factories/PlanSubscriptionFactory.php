<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanSubscription>
 */
class PlanSubscriptionFactory extends Factory
{
    protected $model = PlanSubscription::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'plan_id' => Plan::factory(),
            'stripe_subscription_id' => null,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ];
    }
}
