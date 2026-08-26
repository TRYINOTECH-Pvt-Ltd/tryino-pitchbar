<?php

use App\Http\Controllers\Billing\WebhookController;
use App\Models\Plan;
use App\Models\Workspace;

test('plan exposes lifetime + subscription billing-model accessors', function () {
    $sub = Plan::factory()->create(['billing_model' => Plan::BILLING_SUBSCRIPTION]);
    $ltd = Plan::factory()->create(['billing_model' => Plan::BILLING_LIFETIME]);
    $one = Plan::factory()->create(['billing_model' => Plan::BILLING_ONE_TIME]);

    expect($sub->isSubscription())->toBeTrue();
    expect($sub->isLifetime())->toBeFalse();
    expect($sub->isOneTimePurchase())->toBeFalse();

    expect($ltd->isLifetime())->toBeTrue();
    expect($ltd->isSubscription())->toBeFalse();
    expect($ltd->isOneTimePurchase())->toBeTrue();

    expect($one->isOneTimePurchase())->toBeTrue();
    expect($one->isLifetime())->toBeFalse();
});

test('subscription plan defaults work when billing_model not set explicitly', function () {
    // Existing rows from prior releases back-fill to "subscription"
    // via the migration default — old code paths must keep working.
    $plan = Plan::factory()->create()->fresh();

    expect($plan->billing_model)->toBe(Plan::BILLING_SUBSCRIPTION);
    expect($plan->isSubscription())->toBeTrue();
});

test('workspace effectivePlan returns lifetime plan when one is recorded', function () {
    $subPlan = Plan::factory()->create(['billing_model' => Plan::BILLING_SUBSCRIPTION]);
    $ltdPlan = Plan::factory()->create([
        'billing_model' => Plan::BILLING_LIFETIME,
        'features' => ['remove_branding' => true],
    ]);

    $workspace = Workspace::factory()->create([
        'plan_id' => $subPlan->id,
        'lifetime_plan_id' => $ltdPlan->id,
        'lifetime_purchased_at' => now(),
    ]);

    $effective = $workspace->effectivePlan();
    expect($effective)->not->toBeNull();
    expect($effective->id)->toBe($ltdPlan->id);
    expect($effective->removesBranding())->toBeTrue();
    expect($workspace->hasLifetimeAccess())->toBeTrue();
});

test('workspace without lifetime falls back to the subscription plan', function () {
    $plan = Plan::factory()->create(['billing_model' => Plan::BILLING_SUBSCRIPTION]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);

    expect($workspace->hasLifetimeAccess())->toBeFalse();
    expect($workspace->effectivePlan()?->id)->toBe($plan->id);
});

test('LTD stays in place even when subscription plan is downgraded', function () {
    // Buyer purchased an LTD at $399. Later their saved subscription
    // plan reference flips (e.g. they cancel a parallel subscription).
    // The lifetime unlock must survive.
    $ltdPlan = Plan::factory()->create(['billing_model' => Plan::BILLING_LIFETIME]);
    $freePlan = Plan::factory()->create(['slug' => 'free', 'billing_model' => Plan::BILLING_SUBSCRIPTION]);

    $workspace = Workspace::factory()->create([
        'plan_id' => $freePlan->id,
        'lifetime_plan_id' => $ltdPlan->id,
        'lifetime_purchased_at' => now()->subMonth(),
    ]);

    expect($workspace->effectivePlan()->id)->toBe($ltdPlan->id);
    expect($workspace->hasLifetimeAccess())->toBeTrue();
});

test('checkout.session.completed webhook stamps lifetime_plan_id on the workspace', function () {
    $ltdPlan = Plan::factory()->create([
        'billing_model' => Plan::BILLING_LIFETIME,
        'price_cents' => 39900,
        'name' => 'Lifetime Founder',
    ]);
    $workspace = Workspace::factory()->create([
        'stripe_id' => 'cus_test_lifetime',
    ]);

    $payload = [
        'id' => 'evt_lifetime_'.uniqid(),
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_test_lifetime',
                'mode' => 'payment',
                'customer' => 'cus_test_lifetime',
                'metadata' => [
                    'workspace_id' => $workspace->id,
                    'plan_id' => $ltdPlan->id,
                    'plan_slug' => $ltdPlan->slug,
                    'billing_model' => Plan::BILLING_LIFETIME,
                ],
            ],
        ],
    ];

    // Drive the handler directly so we don't fight Cashier's
    // Stripe-Signature middleware in tests. The handler implements the
    // entire LTD side effect — same path the real webhook dispatcher
    // exercises in production.
    $controller = app(WebhookController::class);
    $reflection = new ReflectionMethod($controller, 'handleCheckoutSessionCompleted');
    $reflection->setAccessible(true);
    $reflection->invoke($controller, $payload);

    $workspace->refresh();
    expect($workspace->lifetime_plan_id)->toBe($ltdPlan->id);
    expect($workspace->lifetime_purchased_at)->not->toBeNull();
    expect($workspace->plan_id)->toBe($ltdPlan->id);
});

test('checkout.session.completed without billing_model metadata is a no-op', function () {
    $workspace = Workspace::factory()->create([
        'stripe_id' => 'cus_test_nonlifetime',
        'lifetime_plan_id' => null,
    ]);

    $payload = [
        'id' => 'evt_session_'.uniqid(),
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_test_subscription',
                'mode' => 'subscription',
                'customer' => 'cus_test_nonlifetime',
                'metadata' => [
                    'workspace_id' => $workspace->id,
                ],
            ],
        ],
    ];

    $controller = app(WebhookController::class);
    $reflection = new ReflectionMethod($controller, 'handleCheckoutSessionCompleted');
    $reflection->setAccessible(true);
    $reflection->invoke($controller, $payload);

    expect($workspace->fresh()->lifetime_plan_id)->toBeNull();
});
