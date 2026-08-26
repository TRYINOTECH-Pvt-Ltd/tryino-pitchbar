<?php

use App\Enums\PlatformRole;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\PayPalClient;
use App\Services\Billing\PayPalProductSync;
use App\Services\Billing\RazorpayClient;
use App\Services\Billing\RazorpayProductSync;
use App\Services\Billing\StripeProductSync;
use Stripe\StripeClient;

beforeEach(function () {
    Plan::query()->delete();
});

test('PATCH /admin/plans/{plan} accepts interval=year', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $plan = Plan::create([
        'name' => 'Pro Annual',
        'slug' => 'pro-annual',
        'monthly_conversations' => 36000,
        'price_cents' => 249000,
        'interval' => 'month',
        'is_active' => true,
    ]);

    $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", [
        'name' => 'Pro Annual',
        'monthly_conversations' => 36000,
        'price_cents' => 249000,
        'interval' => 'year',
        'is_active' => true,
    ])->assertRedirect();

    expect($plan->fresh()->interval)->toBe('year');
});

test('PATCH /admin/plans rejects unknown interval', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $plan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-bad',
        'monthly_conversations' => 3000,
        'price_cents' => 24900,
        'interval' => 'month',
        'is_active' => true,
    ]);

    $this->actingAs($admin)->patch("/admin/plans/{$plan->id}", [
        'name' => 'Pro',
        'monthly_conversations' => 3000,
        'price_cents' => 24900,
        'interval' => 'quarterly',
        'is_active' => true,
    ])->assertSessionHasErrors('interval');
});

test('Stripe Price gets recurring.interval=year for annual plans', function () {
    $plan = Plan::create([
        'name' => 'Pro Annual',
        'slug' => 'pro-yearly-stripe',
        'monthly_conversations' => 36000,
        'price_cents' => 249000,
        'interval' => 'year',
        'is_active' => true,
    ]);

    $captured = null;
    $productsService = Mockery::mock();
    $productsService->shouldReceive('create')->andReturn((object) ['id' => 'prod_y']);

    $pricesService = Mockery::mock();
    $pricesService->shouldReceive('create')
        ->once()
        ->with(Mockery::on(function ($args) use (&$captured) {
            $captured = $args;

            return true;
        }))
        ->andReturn((object) ['id' => 'price_y']);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->products = $productsService;
    $stripe->prices = $pricesService;

    (new StripeProductSync($stripe))->ensurePriceFor($plan);

    expect($captured['recurring']['interval'])->toBe('year');
    expect($captured['metadata']['plan_interval'])->toBe('year');
});

test('PayPal billing_cycles uses YEAR for annual plans', function () {
    $plan = Plan::create([
        'name' => 'Pro Annual',
        'slug' => 'pro-yearly-paypal',
        'monthly_conversations' => 36000,
        'price_cents' => 249000,
        'interval' => 'year',
        'is_active' => true,
    ]);

    $captured = null;
    $paypal = Mockery::mock(PayPalClient::class);
    $paypal->shouldReceive('isConfigured')->andReturn(true);
    $paypal->shouldReceive('createProduct')->andReturn(['id' => 'PROD-YR']);
    $paypal->shouldReceive('createPlan')
        ->once()
        ->with(Mockery::on(function ($payload) use (&$captured) {
            $captured = $payload;

            return true;
        }))
        ->andReturn(['id' => 'PLAN-YR']);

    (new PayPalProductSync($paypal))->ensurePlanFor($plan);

    expect($captured['billing_cycles'][0]['frequency']['interval_unit'])->toBe('YEAR');
    expect($captured['description'])->toContain('Yearly');
});

test('Razorpay period uses yearly for annual plans', function () {
    $plan = Plan::create([
        'name' => 'Pro Annual',
        'slug' => 'pro-yearly-razorpay',
        'monthly_conversations' => 36000,
        'price_cents' => 249000,
        'interval' => 'year',
        'is_active' => true,
    ]);

    $captured = null;
    $rz = Mockery::mock(RazorpayClient::class);
    $rz->shouldReceive('isConfigured')->andReturn(true);
    $rz->shouldReceive('createPlan')
        ->once()
        ->with(Mockery::on(function ($payload) use (&$captured) {
            $captured = $payload;

            return true;
        }))
        ->andReturn(['id' => 'plan_yr']);

    (new RazorpayProductSync($rz))->ensurePlanFor($plan);

    expect($captured['period'])->toBe('yearly');
    expect($captured['notes']['plan_interval'])->toBe('year');
});

test('marketing pricing page exposes monthly + yearly prices from DB plan', function () {
    Plan::query()->delete();
    Plan::factory()->create([
        'name' => 'Standard',
        'slug' => 'standard',
        'is_active' => true,
        'billing_model' => Plan::BILLING_SUBSCRIPTION,
        'price_cents' => 4900,
        'prices' => ['usd' => 4900, 'usd_yearly' => 49000],
        'features' => ['bullets' => []],
    ]);

    $response = $this->get('/pricing')->assertOk();

    $response->assertInertia(fn ($p) => $p
        ->component('marketing/pricing')
        ->has('plans', 1)
        ->where('plans.0.monthly_price', 49)
        ->where('plans.0.yearly_price', 490));
});
