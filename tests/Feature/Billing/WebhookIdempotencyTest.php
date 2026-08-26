<?php

use App\Models\Plan;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        ['name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100, 'price_cents' => 0],
        ['name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500, 'price_cents' => 4900],
    ] as $row) {
        Plan::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [...$row, 'features' => [], 'is_active' => true],
        );
    }

    config()->set('cashier.webhook.secret', '');
    config()->set('services.paypal.webhook_id', '');
    config()->set('services.razorpay.webhook_secret', '');
});

test('Stripe webhook with same event id is processed only once', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['stripe_price_id' => 'price_idem_test'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'stripe_id' => 'cus_idem_test',
    ]);

    $payload = [
        'id' => 'evt_test_idempotent_001',
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_test_idem',
            'customer' => 'cus_idem_test',
            'status' => 'active',
            'metadata' => [],
            'items' => ['data' => [
                ['id' => 'si_1', 'price' => ['id' => 'price_idem_test', 'product' => 'prod_test'], 'quantity' => 1],
            ]],
        ]],
    ];

    // First delivery.
    $this->postJson('/billing/webhook', $payload)->assertOk();
    expect($workspace->fresh()->plan_id)->toBe($standard->id);

    $stored = DB::table('gateway_webhook_events')
        ->where('gateway', 'stripe')->where('event_id', 'evt_test_idempotent_001')->count();
    expect($stored)->toBe(1);

    // Replay with same event id. Should 200 but NOT re-apply the
    // sync (we check the workspace updated_at didn't change).
    $workspace = $workspace->fresh();
    $stamp = $workspace->updated_at;
    sleep(1);
    $this->postJson('/billing/webhook', $payload)->assertOk();

    $workspace = $workspace->fresh();
    expect($workspace->updated_at->equalTo($stamp))->toBeTrue();
    expect(DB::table('gateway_webhook_events')
        ->where('gateway', 'stripe')->where('event_id', 'evt_test_idempotent_001')->count())
        ->toBe(1);
});

test('PayPal webhook with same event id is processed only once', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['paypal_plan_id' => 'PAY-PLAN-IDEM'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);

    $payload = [
        'id' => 'WH-PAYPAL-IDEM-001',
        'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        'resource' => [
            'id' => 'I-PAYPAL-SUB-001',
            'plan_id' => 'PAY-PLAN-IDEM',
            'custom_id' => $workspace->id,
        ],
    ];

    $this->postJson('/billing/webhook/paypal', $payload)->assertOk();
    expect($workspace->fresh()->plan_id)->toBe($standard->id);

    // Replay.
    $this->postJson('/billing/webhook/paypal', $payload)
        ->assertOk()
        ->assertJsonPath('replay', true);

    expect(DB::table('gateway_webhook_events')
        ->where('gateway', 'paypal')->where('event_id', 'WH-PAYPAL-IDEM-001')->count())
        ->toBe(1);
});

test('Razorpay webhook with same (subscription_id, event) is processed only once', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['razorpay_plan_id' => 'plan_idem_rzp'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);

    $body = json_encode([
        'event' => 'subscription.activated',
        'payload' => [
            'subscription' => [
                'entity' => [
                    'id' => 'sub_rzp_idem_001',
                    'plan_id' => 'plan_idem_rzp',
                    'status' => 'active',
                    'notes' => ['workspace_id' => $workspace->id],
                ],
            ],
        ],
    ]);

    $this->call('POST', '/billing/webhook/razorpay', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();
    expect($workspace->fresh()->plan_id)->toBe($standard->id);

    // Replay.
    $this->call('POST', '/billing/webhook/razorpay', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk()->assertJsonPath('replay', true);

    expect(DB::table('gateway_webhook_events')
        ->where('gateway', 'razorpay')
        ->where('event_id', 'sub_rzp_idem_001:subscription.activated')
        ->count())->toBe(1);
});

test('Razorpay grant is BLOCKED when subscription.updated arrives with non-funding status', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['razorpay_plan_id' => 'plan_unfunded'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);

    // `authenticated` is a pre-payment state Razorpay fires
    // subscription.updated for. Pre-fix this granted the plan even
    // though the customer hadn't paid; the workspace would walk away
    // with paid features they never funded.
    $body = json_encode([
        'event' => 'subscription.updated',
        'payload' => [
            'subscription' => [
                'entity' => [
                    'id' => 'sub_unfunded_001',
                    'plan_id' => 'plan_unfunded',
                    'status' => 'authenticated', // NOT funding
                    'notes' => ['workspace_id' => $workspace->id],
                ],
            ],
        ],
    ]);

    $this->call('POST', '/billing/webhook/razorpay', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    // Plan must STILL be free — webhook arrived before payment captured.
    expect($workspace->fresh()->plan_id)->toBe($free->id);
});

test('Razorpay grant fires when subscription.charged arrives even with intermediate status', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['razorpay_plan_id' => 'plan_charged'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);

    $body = json_encode([
        'event' => 'subscription.charged',
        'payload' => [
            'subscription' => [
                'entity' => [
                    'id' => 'sub_charged_001',
                    'plan_id' => 'plan_charged',
                    'status' => 'pending', // status doesn't matter for charged
                    'notes' => ['workspace_id' => $workspace->id],
                ],
            ],
        ],
    ]);

    $this->call('POST', '/billing/webhook/razorpay', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($standard->id);
});
