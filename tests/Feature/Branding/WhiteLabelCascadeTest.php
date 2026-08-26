<?php

use App\Models\AppSetting;
use App\Models\Plan;
use App\Services\Billing\PayPalClient;
use App\Services\Billing\PayPalProductSync;
use App\Services\Billing\RazorpayClient;
use App\Services\Billing\RazorpayProductSync;
use App\Services\Billing\StripeProductSync;
use App\Support\AppBranding;
use Stripe\StripeClient;

/**
 * The buyer renames the install — every place that names "Pitchbar" to
 * an end-user (Stripe Dashboard product names, PayPal subscription
 * descriptors, Razorpay item names, OpenRouter analytics, captured-lead
 * email footer) has to follow.
 *
 * Internal-only mentions (CLAUDE.md, README.md, demo seed data,
 * config defaults) stay — those are not visible to the buyer's customers.
 */
beforeEach(function () {
    AppSetting::query()->updateOrCreate(['id' => 1], [
        'site_title' => 'AcmeBot',
    ]);
    AppSetting::flushSingleton();
});

test('AppBranding::siteTitle reflects the AppSetting override', function () {
    expect(AppBranding::siteTitle())->toBe('AcmeBot');
});

test('Stripe products are named with the active brand, not Pitchbar', function () {
    $plan = Plan::query()->updateOrCreate(
        ['slug' => 'standard'],
        ['name' => 'Standard', 'monthly_conversations' => 500, 'price_cents' => 4900, 'features' => [], 'is_active' => true],
    );

    $captured = null;
    $productsService = Mockery::mock();
    $productsService->shouldReceive('create')
        ->once()
        ->with(Mockery::on(function ($args) use (&$captured) {
            $captured = $args;

            return true;
        }))
        ->andReturn((object) ['id' => 'prod_brand']);

    $pricesService = Mockery::mock();
    $pricesService->shouldReceive('create')->once()->andReturn((object) ['id' => 'price_brand']);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->products = $productsService;
    $stripe->prices = $pricesService;

    (new StripeProductSync($stripe))->ensurePriceFor($plan);

    expect($captured['name'])->toBe('AcmeBot Standard');
});

test('PayPal product + plan names follow the brand', function () {
    $plan = Plan::query()->updateOrCreate(
        ['slug' => 'standard-pp'],
        ['name' => 'Premium', 'monthly_conversations' => 500, 'price_cents' => 4900, 'features' => [], 'is_active' => true],
    );

    $paypal = Mockery::mock(PayPalClient::class);
    $paypal->shouldReceive('isConfigured')->andReturn(true);
    $paypal->shouldReceive('createProduct')
        ->once()
        ->with('AcmeBot Premium', 'AcmeBot subscription plan: Premium')
        ->andReturn(['id' => 'PROD-1']);
    $paypal->shouldReceive('createPlan')
        ->once()
        ->with(Mockery::on(fn ($payload) => $payload['name'] === 'AcmeBot Premium'))
        ->andReturn(['id' => 'PLAN-1']);

    (new PayPalProductSync($paypal))->ensurePlanFor($plan);

    expect($plan->fresh()->paypal_plan_id)->toBe('PLAN-1');
});

test('Razorpay item name follows the brand', function () {
    $plan = Plan::query()->updateOrCreate(
        ['slug' => 'standard-rz'],
        ['name' => 'Pro', 'monthly_conversations' => 500, 'price_cents' => 4900, 'features' => [], 'is_active' => true],
    );

    $rz = Mockery::mock(RazorpayClient::class);
    $rz->shouldReceive('isConfigured')->andReturn(true);
    $rz->shouldReceive('createPlan')
        ->once()
        ->with(Mockery::on(fn ($payload) => $payload['item']['name'] === 'AcmeBot Pro'))
        ->andReturn(['id' => 'plan_rzp_brand']);

    (new RazorpayProductSync($rz))->ensurePlanFor($plan);

    expect($plan->fresh()->razorpay_plan_id)->toBe('plan_rzp_brand');
});
