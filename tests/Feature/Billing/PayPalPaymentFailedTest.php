<?php

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    config()->set('services.paypal.webhook_id', '');
});

test('PAYMENT.FAILED reverts workspace to free immediately (no grace period)', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();

    $workspace = Workspace::factory()->create([
        'plan_id' => $standard->id,
        'payment_gateway' => 'paypal',
        'paypal_subscription_id' => 'I-PAYPAL-CHARGE-FAIL',
    ]);

    $this->postJson('/billing/webhook/paypal', [
        'id' => 'WH-PAYPAL-FAIL-001',
        'event_type' => 'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        'resource' => [
            'id' => 'I-PAYPAL-CHARGE-FAIL',
            'billing_agreement_id' => 'I-PAYPAL-CHARGE-FAIL',
        ],
    ])->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($free->id);
});

test('PAYMENT.COMPLETED is informational — does NOT mutate workspace state', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $workspace = Workspace::factory()->create([
        'plan_id' => $standard->id,
        'payment_gateway' => 'paypal',
        'paypal_subscription_id' => 'I-PAYPAL-CHARGE-OK',
    ]);

    $this->postJson('/billing/webhook/paypal', [
        'id' => 'WH-PAYPAL-OK-001',
        'event_type' => 'BILLING.SUBSCRIPTION.PAYMENT.COMPLETED',
        'resource' => [
            'id' => 'I-PAYPAL-CHARGE-OK',
            'billing_agreement_id' => 'I-PAYPAL-CHARGE-OK',
            'amount' => ['total' => '49.00', 'currency' => 'USD'],
        ],
    ])->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($standard->id);
});

test('PlanSubscription ledger row is upserted on PayPal grant + revoke', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['paypal_plan_id' => 'PAY-PLAN-LEDGER'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create(['plan_id' => $free->id]);

    // Grant.
    $this->postJson('/billing/webhook/paypal', [
        'id' => 'WH-LEDGER-001',
        'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        'resource' => [
            'id' => 'I-LEDGER-SUB',
            'plan_id' => 'PAY-PLAN-LEDGER',
            'custom_id' => $workspace->id,
        ],
    ])->assertOk();

    $ledger = PlanSubscription::query()->withoutGlobalScopes()
        ->where('gateway', 'paypal')
        ->where('gateway_subscription_id', 'I-LEDGER-SUB')
        ->first();
    expect($ledger)->not->toBeNull();
    expect($ledger->status)->toBe('active');
    expect($ledger->workspace_id)->toBe($workspace->id);
    expect($ledger->plan_id)->toBe($standard->id);

    // Revoke. Same (gateway, subscription_id) → updated, not duplicated.
    $this->postJson('/billing/webhook/paypal', [
        'id' => 'WH-LEDGER-002',
        'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
        'resource' => [
            'id' => 'I-LEDGER-SUB',
            'custom_id' => $workspace->id,
        ],
    ])->assertOk();

    $count = PlanSubscription::query()->withoutGlobalScopes()
        ->where('gateway', 'paypal')
        ->where('gateway_subscription_id', 'I-LEDGER-SUB')
        ->count();
    expect($count)->toBe(1);

    $ledger = $ledger->fresh();
    expect($ledger->status)->toBe('canceled');
    expect($ledger->plan_id)->toBeNull();
});
