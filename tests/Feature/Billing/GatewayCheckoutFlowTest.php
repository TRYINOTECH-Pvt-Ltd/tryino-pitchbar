<?php

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function ownerWithGatewayWorkspace(string $planSlug = 'free'): array
{
    foreach ([
        ['name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100, 'price_cents' => 0],
        ['name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500, 'price_cents' => 4900],
    ] as $row) {
        Plan::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [...$row, 'features' => [], 'is_active' => true],
        );
    }

    $plan = Plan::query()->where('slug', $planSlug)->first();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan?->id,
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

beforeEach(function () {
    Cache::flush();
});

test('checkout rejects an unconfigured gateway with a friendly error', function () {
    config()->set('cashier.enabled', true);
    config()->set('cashier.secret', 'sk_test_dummy');
    config()->set('services.paypal.enabled', false);

    ['user' => $user] = ownerWithGatewayWorkspace();

    $this->actingAs($user)
        ->from('/app/billing')
        ->post('/billing/checkout', [
            'plan_slug' => 'standard',
            'gateway' => 'paypal',
        ])
        ->assertRedirect('/app/billing')
        ->assertSessionHas('error');
});

test('checkout dispatches to PayPal and redirects to the approval URL', function () {
    config()->set('services.paypal.enabled', true);
    config()->set('services.paypal.mode', 'sandbox');
    config()->set('services.paypal.client_id', 'cid');
    config()->set('services.paypal.client_secret', 'csecret');

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 32400], 200),
        '*/v1/catalogs/products' => Http::response(['id' => 'PROD-CHECK'], 201),
        '*/v1/billing/plans' => Http::response(['id' => 'P-PLAN-CHECK', 'status' => 'ACTIVE'], 201),
        '*/v1/billing/subscriptions' => Http::response([
            'id' => 'I-SUB-CHECK',
            'links' => [
                ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ABC'],
            ],
        ], 201),
    ]);

    ['user' => $user, 'workspace' => $workspace] = ownerWithGatewayWorkspace();

    $this->actingAs($user)
        ->post('/billing/checkout', [
            'plan_slug' => 'standard',
            'gateway' => 'paypal',
        ])
        ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ABC');

    $fresh = $workspace->fresh();
    expect($fresh->payment_gateway)->toBe('paypal');
    expect($fresh->paypal_subscription_id)->toBe('I-SUB-CHECK');
});

test('checkout dispatches to Razorpay and renders the launcher page', function () {
    config()->set('services.razorpay.enabled', true);
    config()->set('services.razorpay.key_id', 'rzp_test_x');
    config()->set('services.razorpay.key_secret', 'rzp_secret');

    Http::fake([
        '*/v1/plans' => Http::response(['id' => 'plan_RZ_X'], 200),
        '*/v1/subscriptions' => Http::response([
            'id' => 'sub_RZ_X',
            'status' => 'created',
        ], 200),
    ]);

    ['user' => $user, 'workspace' => $workspace] = ownerWithGatewayWorkspace();

    $response = $this->actingAs($user)
        ->post('/billing/checkout', [
            'plan_slug' => 'standard',
            'gateway' => 'razorpay',
        ]);

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->component('app/razorpay-checkout')
            ->where('subscription_id', 'sub_RZ_X')
            ->where('razorpay_key_id', 'rzp_test_x'),
    );

    $fresh = $workspace->fresh();
    expect($fresh->payment_gateway)->toBe('razorpay');
    expect($fresh->razorpay_subscription_id)->toBe('sub_RZ_X');
});

test('billing page exposes the list of enabled gateways', function () {
    config()->set('cashier.enabled', true);
    config()->set('cashier.secret', 'sk_test_dummy');
    config()->set('services.paypal.enabled', true);
    config()->set('services.paypal.client_id', 'cid');
    config()->set('services.paypal.client_secret', 'csecret');
    config()->set('services.razorpay.enabled', false);

    ['user' => $user] = ownerWithGatewayWorkspace();

    $this->actingAs($user)
        ->get('/app/billing')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->component('app/billing')
                ->where('available_gateways', ['stripe', 'paypal']),
        );
});
