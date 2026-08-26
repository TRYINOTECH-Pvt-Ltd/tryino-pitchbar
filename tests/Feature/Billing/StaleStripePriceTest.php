<?php

use App\Models\Plan;
use App\Services\Billing\StripeProductSync;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

function stalePricePlan(): Plan
{
    return Plan::factory()->create([
        'slug' => 'standard-'.uniqid(),
        'price_cents' => 4900,
        'is_active' => true,
        'stripe_product_id' => 'prod_test_stale',
        'stripe_price_id' => 'price_test_stale',
    ]);
}

function stripeClientMock(): array
{
    $prices = Mockery::mock();
    $products = Mockery::mock();
    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('getService')->with('prices')->andReturn($prices);
    $client->shouldReceive('getService')->with('products')->andReturn($products);

    return [$client, $prices, $products];
}

it('heals a test-mode price id and re-mints product + price under the current key', function () {
    $plan = stalePricePlan();
    [$client, $prices, $products] = stripeClientMock();

    // Probe: live key does not know the test-mode price.
    $prices->shouldReceive('retrieve')->with('price_test_stale')->once()
        ->andThrow(new InvalidRequestException("No such price: 'price_test_stale'; a similar object exists in test mode"));

    // Heal: syncPlan re-mints under the current key.
    $products->shouldReceive('create')->once()->andReturn((object) ['id' => 'prod_live_new']);
    $prices->shouldReceive('create')->once()->andReturn((object) ['id' => 'price_live_new']);

    $priceId = (new StripeProductSync($client))->ensurePriceFor($plan);

    expect($priceId)->toBe('price_live_new')
        ->and($plan->fresh()->stripe_price_id)->toBe('price_live_new')
        ->and($plan->fresh()->stripe_product_id)->toBe('prod_live_new');
});

it('keeps the saved price id when the probe fails for non-missing reasons', function () {
    $plan = stalePricePlan();
    [$client, $prices] = stripeClientMock();

    // Auth error (bad key) — the probe must NOT heal or block; the saved
    // id is returned and the real checkout call surfaces the problem.
    $prices->shouldReceive('retrieve')->with('price_test_stale')->once()
        ->andThrow(new AuthenticationException('Invalid API Key provided'));

    $priceId = (new StripeProductSync($client))->ensurePriceFor($plan);

    expect($priceId)->toBe('price_test_stale')
        ->and($plan->fresh()->stripe_price_id)->toBe('price_test_stale');
});

it('returns the saved id untouched when the price exists', function () {
    $plan = stalePricePlan();
    [$client, $prices] = stripeClientMock();

    $prices->shouldReceive('retrieve')->with('price_test_stale')->once()
        ->andReturn((object) ['id' => 'price_test_stale']);

    expect((new StripeProductSync($client))->ensurePriceFor($plan))->toBe('price_test_stale');
});
