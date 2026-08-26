<?php

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Workspace;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\SubscriptionReconciler;
use Illuminate\Support\Facades\Http;
use Stripe\StripeClient;

beforeEach(function () {
    foreach ([
        ['name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100, 'price_cents' => 0],
        ['name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500, 'price_cents' => 4900, 'stripe_price_id' => 'price_standard'],
        ['name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000, 'price_cents' => 24900, 'stripe_price_id' => 'price_pro'],
    ] as $row) {
        Plan::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [...$row, 'features' => [], 'is_active' => true],
        );
    }
});

function reconcilerWithStripeStub(callable $stub): SubscriptionReconciler
{
    $stripeMock = Mockery::mock(StripeClient::class)->makePartial();
    $stub($stripeMock);
    app()->instance(StripeClient::class, $stripeMock);

    return app(SubscriptionReconciler::class);
}

test('stripe reconcile flips plan_id when session_id resolves to an active subscription', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'stripe_id' => null,
        'payment_gateway' => PaymentGatewayRegistry::STRIPE,
    ]);

    $sessionsService = Mockery::mock();
    $sessionsService->shouldReceive('retrieve')
        ->once()
        ->with('cs_test_123', Mockery::any())
        ->andReturn((object) [
            'id' => 'cs_test_123',
            'customer' => 'cus_recon_test',
            'subscription' => 'sub_recon_test',
        ]);

    $checkoutSvc = Mockery::mock();
    $checkoutSvc->sessions = $sessionsService;

    $subscriptionsService = Mockery::mock();
    $subscriptionsService->shouldReceive('retrieve')
        ->once()
        ->with('sub_recon_test')
        ->andReturn((object) [
            'id' => 'sub_recon_test',
            'customer' => 'cus_recon_test',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_end' => time() + 86400,
            'items' => (object) [
                'data' => [
                    (object) ['price' => (object) ['id' => 'price_standard']],
                ],
            ],
        ]);

    $reconciler = reconcilerWithStripeStub(function ($stripe) use ($checkoutSvc, $subscriptionsService) {
        $stripe->checkout = $checkoutSvc;
        $stripe->subscriptions = $subscriptionsService;
    });

    $plan = $reconciler->reconcile($workspace, 'cs_test_123');

    expect($plan)->not->toBeNull();
    expect($plan->id)->toBe($standard->id);
    $fresh = $workspace->fresh();
    expect($fresh->plan_id)->toBe($standard->id);
    expect($fresh->stripe_id)->toBe('cus_recon_test');
    expect(PlanSubscription::query()
        ->where('gateway', PlanSubscription::GATEWAY_STRIPE)
        ->where('gateway_subscription_id', 'sub_recon_test')
        ->where('plan_id', $standard->id)
        ->exists())->toBeTrue();
});

test('stripe reconcile without session_id falls back to listing subscriptions by stripe_id', function () {
    $pro = Plan::query()->where('slug', 'pro')->firstOrFail();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'stripe_id' => 'cus_already_linked',
        'payment_gateway' => PaymentGatewayRegistry::STRIPE,
    ]);

    $listResponse = (object) [
        'data' => [
            (object) [
                'id' => 'sub_existing_pro',
                'status' => 'active',
            ],
        ],
    ];

    $subscriptionsService = Mockery::mock();
    $subscriptionsService->shouldReceive('all')->once()->andReturn($listResponse);
    $subscriptionsService->shouldReceive('retrieve')
        ->once()
        ->with('sub_existing_pro')
        ->andReturn((object) [
            'id' => 'sub_existing_pro',
            'customer' => 'cus_already_linked',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_end' => time() + 86400,
            'items' => (object) [
                'data' => [
                    (object) ['price' => (object) ['id' => 'price_pro']],
                ],
            ],
        ]);

    $reconciler = reconcilerWithStripeStub(function ($stripe) use ($subscriptionsService) {
        $stripe->subscriptions = $subscriptionsService;
    });

    $plan = $reconciler->reconcile($workspace);

    expect($plan?->id)->toBe($pro->id);
    expect($workspace->fresh()->plan_id)->toBe($pro->id);
});

test('stripe reconcile leaves plan_id alone when subscription is not in a paying status', function () {
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'stripe_id' => 'cus_incomplete_test',
        'payment_gateway' => PaymentGatewayRegistry::STRIPE,
    ]);

    $subscriptionsService = Mockery::mock();
    $subscriptionsService->shouldReceive('all')->once()->andReturn((object) ['data' => []]);

    $reconciler = reconcilerWithStripeStub(function ($stripe) use ($subscriptionsService) {
        $stripe->subscriptions = $subscriptionsService;
    });

    $plan = $reconciler->reconcile($workspace);

    expect($plan)->toBeNull();
    expect($workspace->fresh()->plan_id)->toBe($free->id);
});

test('paypal reconcile flips plan_id when the subscription is ACTIVE', function () {
    config()->set('services.paypal.mode', 'sandbox');
    config()->set('services.paypal.client_id', 'cid');
    config()->set('services.paypal.client_secret', 'csec');

    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['paypal_plan_id' => 'P-PP-STD'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();

    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'paypal_subscription_id' => 'I-SUB-PP-123',
        'payment_gateway' => PaymentGatewayRegistry::PAYPAL,
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v1/billing/subscriptions/I-SUB-PP-123' => Http::response([
            'id' => 'I-SUB-PP-123',
            'status' => 'ACTIVE',
            'plan_id' => 'P-PP-STD',
        ]),
    ]);

    $reconciler = app(SubscriptionReconciler::class);
    $plan = $reconciler->reconcile($workspace);

    expect($plan?->id)->toBe($standard->id);
    expect($workspace->fresh()->plan_id)->toBe($standard->id);
});

test('paypal reconcile is a no-op when subscription is APPROVAL_PENDING', function () {
    config()->set('services.paypal.mode', 'sandbox');
    config()->set('services.paypal.client_id', 'cid');
    config()->set('services.paypal.client_secret', 'csec');

    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $standard->forceFill(['paypal_plan_id' => 'P-PP-STD'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();

    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'paypal_subscription_id' => 'I-PEND',
        'payment_gateway' => PaymentGatewayRegistry::PAYPAL,
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v1/billing/subscriptions/I-PEND' => Http::response([
            'id' => 'I-PEND',
            'status' => 'APPROVAL_PENDING',
            'plan_id' => 'P-PP-STD',
        ]),
    ]);

    $plan = app(SubscriptionReconciler::class)->reconcile($workspace);

    expect($plan)->toBeNull();
    expect($workspace->fresh()->plan_id)->toBe($free->id);
});

test('razorpay reconcile flips plan_id when subscription status is active', function () {
    config()->set('services.razorpay.key_id', 'rzp_kid');
    config()->set('services.razorpay.key_secret', 'rzp_secret');

    $pro = Plan::query()->where('slug', 'pro')->firstOrFail();
    $pro->forceFill(['razorpay_plan_id' => 'plan_rzp_pro'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();

    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'razorpay_subscription_id' => 'sub_rzp_123',
        'payment_gateway' => PaymentGatewayRegistry::RAZORPAY,
    ]);

    Http::fake([
        '*/v1/subscriptions/sub_rzp_123' => Http::response([
            'id' => 'sub_rzp_123',
            'status' => 'active',
            'plan_id' => 'plan_rzp_pro',
        ]),
    ]);

    $plan = app(SubscriptionReconciler::class)->reconcile($workspace);

    expect($plan?->id)->toBe($pro->id);
    expect($workspace->fresh()->plan_id)->toBe($pro->id);
});

test('razorpay reconcile is a no-op when subscription status is created', function () {
    config()->set('services.razorpay.key_id', 'rzp_kid');
    config()->set('services.razorpay.key_secret', 'rzp_secret');

    $pro = Plan::query()->where('slug', 'pro')->firstOrFail();
    $pro->forceFill(['razorpay_plan_id' => 'plan_rzp_pro'])->save();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();

    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'razorpay_subscription_id' => 'sub_created',
        'payment_gateway' => PaymentGatewayRegistry::RAZORPAY,
    ]);

    Http::fake([
        '*/v1/subscriptions/sub_created' => Http::response([
            'id' => 'sub_created',
            'status' => 'created',
            'plan_id' => 'plan_rzp_pro',
        ]),
    ]);

    $plan = app(SubscriptionReconciler::class)->reconcile($workspace);

    expect($plan)->toBeNull();
    expect($workspace->fresh()->plan_id)->toBe($free->id);
});

test('reconcile returns null when workspace has no payment_gateway set', function () {
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    $workspace = Workspace::factory()->create([
        'plan_id' => $free->id,
        'payment_gateway' => null,
    ]);

    $plan = app(SubscriptionReconciler::class)->reconcile($workspace, 'cs_irrelevant');

    expect($plan)->toBeNull();
    expect($workspace->fresh()->plan_id)->toBe($free->id);
});
