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
});

test('subscription.activated webhook flips workspace plan when signature matches', function () {
    config()->set('services.razorpay.webhook_secret', 'shh-secret');

    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['razorpay_plan_id' => 'plan_RZ_STANDARD'])->save();

    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);

    $payload = [
        'event' => 'subscription.activated',
        'payload' => [
            'subscription' => [
                'entity' => [
                    'id' => 'sub_RZ_001',
                    'plan_id' => 'plan_RZ_STANDARD',
                    'status' => 'active',
                    'notes' => ['workspace_id' => $workspace->id],
                ],
            ],
        ],
    ];

    $rawBody = json_encode($payload);
    $signature = hash_hmac('sha256', $rawBody, 'shh-secret');

    $this->call(
        method: 'POST',
        uri: '/billing/webhook/razorpay',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: $rawBody,
    )->assertOk();

    $fresh = $workspace->fresh();
    expect($fresh->plan_id)->toBe($standard->id);
    expect($fresh->payment_gateway)->toBe('razorpay');
    expect($fresh->razorpay_subscription_id)->toBe('sub_RZ_001');
});

test('subscription.cancelled webhook reverts workspace to free plan', function () {
    config()->set('services.razorpay.webhook_secret', '');

    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['razorpay_plan_id' => 'plan_RZ_STANDARD'])->save();

    $workspace = Workspace::factory()->create([
        'plan_id' => $standard->id,
        'payment_gateway' => 'razorpay',
        'razorpay_subscription_id' => 'sub_RZ_002',
    ]);

    $payload = [
        'event' => 'subscription.cancelled',
        'payload' => [
            'subscription' => [
                'entity' => [
                    'id' => 'sub_RZ_002',
                    'plan_id' => 'plan_RZ_STANDARD',
                    'notes' => ['workspace_id' => $workspace->id],
                ],
            ],
        ],
    ];

    $this->postJson('/billing/webhook/razorpay', $payload)->assertOk();

    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $fresh = $workspace->fresh();
    expect($fresh->plan_id)->toBe($free->id);
    expect($fresh->payment_gateway)->toBeNull();
});

test('webhook rejects payloads with an invalid signature when a secret is configured', function () {
    config()->set('services.razorpay.webhook_secret', 'shh-secret');

    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['razorpay_plan_id' => 'plan_RZ_STANDARD'])->save();

    $workspace = Workspace::factory()->create();

    $payload = json_encode([
        'event' => 'subscription.activated',
        'payload' => [
            'subscription' => [
                'entity' => [
                    'id' => 'sub_RZ_999',
                    'plan_id' => 'plan_RZ_STANDARD',
                    'notes' => ['workspace_id' => $workspace->id],
                ],
            ],
        ],
    ]);

    $this->call(
        method: 'POST',
        uri: '/billing/webhook/razorpay',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => 'wrong-signature',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: $payload,
    )->assertStatus(401);

    expect($workspace->fresh()->plan_id)->toBe($workspace->plan_id);
});

test('webhook ignores unknown subscription notes and leaves workspace untouched', function () {
    config()->set('services.razorpay.webhook_secret', '');

    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['razorpay_plan_id' => 'plan_RZ_STANDARD'])->save();
    $workspace = Workspace::factory()->create(['plan_id' => $standard->id]);

    $payload = [
        'event' => 'subscription.activated',
        'payload' => [
            'subscription' => [
                'entity' => [
                    'id' => 'sub_RZ_unrelated',
                    'plan_id' => 'plan_RZ_STANDARD',
                    'notes' => ['workspace_id' => 'unknown-uuid'],
                ],
            ],
        ],
    ];

    $this->postJson('/billing/webhook/razorpay', $payload)->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($standard->id);
});
