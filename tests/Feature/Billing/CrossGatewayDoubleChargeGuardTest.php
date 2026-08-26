<?php

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function workspaceOnGateway(string $gateway, ?string $subscriptionId = null): array
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
    $standard = Plan::query()->where('slug', 'standard')->firstOrFail();

    $user = User::factory()->create();
    $attributes = [
        'owner_user_id' => $user->id,
        'plan_id' => $standard->id,
        'payment_gateway' => $gateway,
    ];

    if ($gateway === 'stripe') {
        $attributes['stripe_id'] = 'cus_'.uniqid();
    } elseif ($gateway === 'paypal') {
        $attributes['paypal_subscription_id'] = $subscriptionId ?? 'I-PAYPAL-EXISTING';
    } elseif ($gateway === 'razorpay') {
        $attributes['razorpay_subscription_id'] = $subscriptionId ?? 'sub_rzp_existing';
    }

    $workspace = Workspace::factory()->create($attributes);
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
    // Enable all three gateways so the registry can offer them.
    config()->set('cashier.enabled', true);
    config()->set('cashier.secret', 'sk_test_dummy');
    config()->set('services.paypal.enabled', true);
    config()->set('services.paypal.client_id', 'paypal_test_id');
    config()->set('services.paypal.client_secret', 'paypal_test_secret');
    config()->set('services.razorpay.enabled', true);
    config()->set('services.razorpay.key_id', 'rzp_test_key');
    config()->set('services.razorpay.key_secret', 'rzp_test_secret');
});

test('PayPal-active workspace clicking Stripe checkout is BLOCKED with helpful error', function () {
    ['user' => $user] = workspaceOnGateway('paypal');

    $response = $this->actingAs($user)->post('/billing/checkout', [
        'plan_slug' => 'standard',
        'gateway' => 'stripe',
    ]);

    $error = (string) ($response->getSession()?->get('error') ?? '');
    expect(strtolower($error))->toContain('paypal');
    expect($error)->toContain('charged twice');
});

test('Razorpay-active workspace clicking PayPal checkout is BLOCKED', function () {
    ['user' => $user] = workspaceOnGateway('razorpay');

    $this->actingAs($user)
        ->post('/billing/checkout', [
            'plan_slug' => 'standard',
            'gateway' => 'paypal',
        ])
        ->assertRedirect()
        ->assertSessionHas('error', fn ($msg) => str_contains(strtolower((string) $msg), 'razorpay'));
});

test('workspace on the SAME gateway is NOT blocked (allows plan upgrade on same gateway)', function () {
    // A Stripe customer upgrading from Standard to Pro must still
    // pass the guard — same gateway = no double-charge risk, just a
    // plan change.
    Plan::query()->updateOrCreate(
        ['slug' => 'pro'],
        ['name' => 'Pro', 'monthly_conversations' => 3000, 'price_cents' => 24900, 'features' => [], 'is_active' => true],
    );
    ['user' => $user] = workspaceOnGateway('stripe');

    $response = $this->actingAs($user)->post('/billing/checkout', [
        'plan_slug' => 'standard',
        'gateway' => 'stripe',
    ]);

    // Should NOT see the double-charge error. Other validation /
    // outbound-Stripe-call errors may still fire here (no real
    // Stripe key in tests) but the guard itself didn't trip.
    $error = (string) ($response->getSession()?->get('error') ?? '');
    expect($error)->not->toContain('charged twice');
});

test('workspace on a stale gateway with NO active subscription is NOT blocked', function () {
    // Customer's previous Razorpay subscription expired or was
    // canceled — razorpay_subscription_id was cleared. Switching to
    // Stripe must work; there's no active billing to clash with.
    ['user' => $user, 'workspace' => $workspace] = workspaceOnGateway('razorpay', '');
    $workspace->forceFill(['razorpay_subscription_id' => null])->save();

    $response = $this->actingAs($user)->post('/billing/checkout', [
        'plan_slug' => 'standard',
        'gateway' => 'stripe',
    ]);

    $error = (string) ($response->getSession()?->get('error') ?? '');
    expect($error)->not->toContain('charged twice');
});
