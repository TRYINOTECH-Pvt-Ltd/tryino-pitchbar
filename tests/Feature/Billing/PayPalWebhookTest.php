<?php

use App\Models\Plan;
use App\Models\Workspace;

beforeEach(function () {
    foreach ([
        ['name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100, 'price_cents' => 0],
        ['name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500, 'price_cents' => 4900],
        ['name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000, 'price_cents' => 24900],
    ] as $row) {
        Plan::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [...$row, 'features' => [], 'is_active' => true],
        );
    }

    // No webhook id configured → controller skips signature verification.
    config()->set('services.paypal.webhook_id', '');
});

test('subscription.activated webhook flips workspace plan_id', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['paypal_plan_id' => 'P-STANDARD-PLAN'])->save();

    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);

    $payload = [
        'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        'resource' => [
            'id' => 'I-PAYPAL-SUB-001',
            'plan_id' => 'P-STANDARD-PLAN',
            'custom_id' => $workspace->id,
        ],
    ];

    $this->postJson('/billing/webhook/paypal', $payload)->assertOk();

    $fresh = $workspace->fresh();
    expect($fresh->plan_id)->toBe($standard->id);
    expect($fresh->payment_gateway)->toBe('paypal');
    expect($fresh->paypal_subscription_id)->toBe('I-PAYPAL-SUB-001');
});

test('subscription.cancelled webhook reverts workspace to free plan', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['paypal_plan_id' => 'P-STANDARD-PLAN'])->save();

    $workspace = Workspace::factory()->create([
        'plan_id' => $standard->id,
        'payment_gateway' => 'paypal',
        'paypal_subscription_id' => 'I-PAYPAL-SUB-002',
    ]);

    $payload = [
        'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
        'resource' => [
            'id' => 'I-PAYPAL-SUB-002',
            'custom_id' => $workspace->id,
            'plan_id' => 'P-STANDARD-PLAN',
        ],
    ];

    $this->postJson('/billing/webhook/paypal', $payload)->assertOk();

    $fresh = $workspace->fresh();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    expect($fresh->plan_id)->toBe($free->id);
    expect($fresh->payment_gateway)->toBeNull();
});

test('webhook ignores unknown plans and leaves workspace untouched', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $standard->id]);

    $payload = [
        'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        'resource' => [
            'id' => 'I-PAYPAL-SUB-003',
            'plan_id' => 'P-UNKNOWN-PLAN',
            'custom_id' => $workspace->id,
        ],
    ];

    $this->postJson('/billing/webhook/paypal', $payload)->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($standard->id);
});

test('webhook rejects payloads without an event_type', function () {
    $this->postJson('/billing/webhook/paypal', [
        'resource' => ['id' => 'whatever'],
    ])->assertStatus(400);
});

test('webhook resolves workspace via paypal_subscription_id when custom_id is absent', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['paypal_plan_id' => 'P-STANDARD-PLAN'])->save();

    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'paypal_subscription_id' => 'I-PAYPAL-SUB-004',
    ]);

    $payload = [
        'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        'resource' => [
            'id' => 'I-PAYPAL-SUB-004',
            'plan_id' => 'P-STANDARD-PLAN',
        ],
    ];

    $this->postJson('/billing/webhook/paypal', $payload)->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($standard->id);
});
