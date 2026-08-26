<?php

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Workspace;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\StripeProductSync;
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

function lifecycleOwner(array $workspaceOverrides = []): array
{
    $bag = workspaceMember(['role' => 'owner']);
    $bag['workspace']->forceFill($workspaceOverrides)->save();

    return $bag;
}

test('cancel on stripe with no active sub returns the not-found error', function () {
    // Workspace claims Stripe as gateway but has no Cashier subscription
    // row. Controller should return back with an error, NOT 500 on a null
    // subscription dereference.
    ['user' => $user] = lifecycleOwner([
        'payment_gateway' => PaymentGatewayRegistry::STRIPE,
        'stripe_id' => 'cus_no_sub',
    ]);

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/cancel')
        ->assertRedirect('/app/billing')
        ->assertSessionHas('error');
});

test('cancel on paypal calls PayPalClient->cancelSubscription and records the ledger', function () {
    config()->set('services.paypal.mode', 'sandbox');
    config()->set('services.paypal.client_id', 'cid');
    config()->set('services.paypal.client_secret', 'csec');

    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    ['user' => $user, 'workspace' => $workspace] = lifecycleOwner([
        'plan_id' => $standard->id,
        'payment_gateway' => PaymentGatewayRegistry::PAYPAL,
        'paypal_subscription_id' => 'I-SUB-PP',
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v1/billing/subscriptions/I-SUB-PP/cancel' => Http::response('', 204),
    ]);

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/cancel')
        ->assertRedirect('/app/billing');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/billing/subscriptions/I-SUB-PP/cancel'));
    expect(PlanSubscription::query()
        ->where('gateway', PlanSubscription::GATEWAY_PAYPAL)
        ->where('gateway_subscription_id', 'I-SUB-PP')
        ->where('status', 'canceled')
        ->exists())->toBeTrue();
});

test('cancel on razorpay calls RazorpayClient->cancelSubscription at cycle end', function () {
    config()->set('services.razorpay.key_id', 'rzp_kid');
    config()->set('services.razorpay.key_secret', 'rzp_secret');

    $pro = Plan::query()->where('slug', 'pro')->firstOrFail();
    ['user' => $user, 'workspace' => $workspace] = lifecycleOwner([
        'plan_id' => $pro->id,
        'payment_gateway' => PaymentGatewayRegistry::RAZORPAY,
        'razorpay_subscription_id' => 'sub_rzp_cancel',
    ]);

    Http::fake([
        '*/v1/subscriptions/sub_rzp_cancel/cancel' => Http::response(['id' => 'sub_rzp_cancel', 'status' => 'cancelled'], 200),
    ]);

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/cancel')
        ->assertRedirect('/app/billing');

    Http::assertSent(function ($r) {
        return str_contains($r->url(), '/v1/subscriptions/sub_rzp_cancel/cancel')
            && ($r->data()['cancel_at_cycle_end'] ?? null) === 1;
    });
    expect(PlanSubscription::query()
        ->where('gateway', PlanSubscription::GATEWAY_RAZORPAY)
        ->where('gateway_subscription_id', 'sub_rzp_cancel')
        ->where('status', 'canceled')
        ->exists())->toBeTrue();
});

test('cancel returns an error when no subscription is on file', function () {
    $free = Plan::query()->where('slug', 'free')->firstOrFail();
    ['user' => $user] = lifecycleOwner([
        'plan_id' => $free->id,
        'payment_gateway' => null,
    ]);

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/cancel')
        ->assertRedirect('/app/billing')
        ->assertSessionHas('error');
});

test('resume on paypal or razorpay returns a friendly error (not supported)', function () {
    ['user' => $user] = lifecycleOwner([
        'payment_gateway' => PaymentGatewayRegistry::PAYPAL,
        'paypal_subscription_id' => 'I-SUB',
    ]);

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/resume')
        ->assertRedirect('/app/billing')
        ->assertSessionHas('error');

    ['user' => $user2] = lifecycleOwner([
        'payment_gateway' => PaymentGatewayRegistry::RAZORPAY,
        'razorpay_subscription_id' => 'sub_rzp',
    ]);

    $this->actingAs($user2)
        ->from('/app/billing')
        ->post('/billing/resume')
        ->assertRedirect('/app/billing')
        ->assertSessionHas('error');
});

test('swap on non-stripe returns the cancel+re-checkout guidance error', function () {
    ['user' => $user] = lifecycleOwner([
        'payment_gateway' => PaymentGatewayRegistry::PAYPAL,
        'paypal_subscription_id' => 'I-SUB',
    ]);

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/swap', ['plan_slug' => 'pro'])
        ->assertRedirect('/app/billing')
        ->assertSessionHas('error');
});

test('swap on stripe calls ensurePriceFor + swap', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $pro = Plan::query()->where('slug', 'pro')->firstOrFail();

    ['user' => $user, 'workspace' => $workspace] = lifecycleOwner([
        'plan_id' => $standard->id,
        'payment_gateway' => PaymentGatewayRegistry::STRIPE,
        'stripe_id' => 'cus_swap_test',
    ]);

    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_swap_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_standard',
        'quantity' => 1,
    ]);

    $stripeSync = Mockery::mock(StripeProductSync::class);
    $stripeSync->shouldReceive('ensurePriceFor')->once()->andReturn('price_pro');
    app()->instance(StripeProductSync::class, $stripeSync);

    // Stripe SDK mock for Cashier's swap.
    $stripeSub = Mockery::mock();
    $stripeSub->cancel_at_period_end = false;
    $stripeSub->current_period_end = time() + 86400;
    $stripeSub->status = 'active';
    $stripeSub->items = (object) [
        'data' => [
            (object) [
                'id' => 'si_existing',
                'price' => (object) ['id' => 'price_pro'],
            ],
        ],
    ];

    $subscriptionsService = Mockery::mock();
    $subscriptionsService->shouldReceive('retrieve')->andReturn($stripeSub);
    $subscriptionsService->shouldReceive('update')->andReturn($stripeSub);

    $stripeMock = Mockery::mock(StripeClient::class)->makePartial();
    $stripeMock->subscriptions = $subscriptionsService;
    app()->instance(StripeClient::class, $stripeMock);

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/swap', ['plan_slug' => 'pro'])
        ->assertRedirect('/app/billing');
});

test('non-billing-admin (viewer) cannot cancel', function () {
    $bag = workspaceMember(['role' => 'viewer']);
    $bag['workspace']->forceFill([
        'payment_gateway' => PaymentGatewayRegistry::PAYPAL,
        'paypal_subscription_id' => 'I-SUB',
    ])->save();

    $this->actingAs($bag['user'])
        ->from('/app/billing')
        ->post('/billing/cancel')
        ->assertForbidden();
});

test('billing.show invokes the reconciler when checkout=success arrives', function () {
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();

    ['user' => $user, 'workspace' => $workspace] = lifecycleOwner([
        'plan_id' => $free->id,
        'payment_gateway' => PaymentGatewayRegistry::STRIPE,
    ]);

    $sessionsService = Mockery::mock();
    $sessionsService->shouldReceive('retrieve')->andReturn((object) [
        'id' => 'cs_recon_int',
        'customer' => 'cus_int',
        'subscription' => 'sub_int',
    ]);
    $checkout = Mockery::mock();
    $checkout->sessions = $sessionsService;

    $subscriptionsService = Mockery::mock();
    $subscriptionsService->shouldReceive('retrieve')
        ->andReturn((object) [
            'id' => 'sub_int',
            'customer' => 'cus_int',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_end' => time() + 86400,
            'items' => (object) [
                'data' => [(object) ['price' => (object) ['id' => 'price_standard']]],
            ],
        ]);

    $stripeMock = Mockery::mock(StripeClient::class)->makePartial();
    $stripeMock->checkout = $checkout;
    $stripeMock->subscriptions = $subscriptionsService;
    app()->instance(StripeClient::class, $stripeMock);

    $this->actingAs($user)
        ->get('/app/billing?checkout=success&session_id=cs_recon_int')
        ->assertOk();

    expect($workspace->fresh()->plan_id)->toBe($standard->id);
});
