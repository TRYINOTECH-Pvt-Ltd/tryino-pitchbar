<?php

use App\Models\Plan;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Billing\MeteredBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The marketing demo workspace (`slug = pitchbar-demo`) backs the
 * live widget on the public landing page. It must NEVER be subject
 * to a plan limit — a 429 there means real marketing visitors cannot
 * even start a conversation with the demo bot, which kills the
 * top-of-funnel demo entirely.
 */
test('demo workspace bypasses the conversation plan limit', function () {
    $freePlan = Plan::create([
        'name' => 'Free',
        'slug' => 'free',
        'monthly_conversations' => 5,
        'price_cents' => 0,
        'is_active' => true,
    ]);

    $demo = Workspace::factory()->create([
        'slug' => 'pitchbar-demo',
        'plan_id' => $freePlan->id,
    ]);

    // Burn through the cap.
    for ($i = 0; $i < 50; $i++) {
        UsageEvent::create([
            'workspace_id' => $demo->id,
            'kind' => 'conversation',
            'quantity' => 1,
            'occurred_at' => now(),
        ]);
    }

    $billing = app(MeteredBilling::class);

    // 50 conversations against a 5/month cap is 10x over for any
    // regular workspace — demo workspace must still allow new starts.
    expect($billing->canStartConversation($demo))->toBeTrue();
    expect($billing->canSendMessage($demo))->toBeTrue();
});

test('non-demo workspaces still respect the plan limit', function () {
    $freePlan = Plan::create([
        'name' => 'Free',
        'slug' => 'free',
        'monthly_conversations' => 3,
        'price_cents' => 0,
        'is_active' => true,
    ]);

    $tenant = Workspace::factory()->create([
        'slug' => 'acme-co',
        'plan_id' => $freePlan->id,
    ]);

    for ($i = 0; $i < 4; $i++) {
        UsageEvent::create([
            'workspace_id' => $tenant->id,
            'kind' => 'conversation',
            'quantity' => 1,
            'occurred_at' => now(),
        ]);
    }

    expect(app(MeteredBilling::class)->canStartConversation($tenant))->toBeFalse();
});
