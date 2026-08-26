<?php

use App\Models\Plan;
use App\Services\Billing\StripeProductSync;
use Stripe\StripeClient;

beforeEach(function () {
    $this->plan = Plan::query()->updateOrCreate(
        ['slug' => 'standard'],
        [
            'name' => 'Standard',
            'monthly_conversations' => 500,
            'price_cents' => 4900,
            'features' => [],
            'is_active' => true,
        ],
    );
});

test('ensurePriceFor reuses an already-saved stripe_price_id', function () {
    $this->plan->forceFill(['stripe_price_id' => 'price_existing_123'])->save();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->shouldNotReceive('products');
    $stripe->shouldNotReceive('prices');

    $sync = new StripeProductSync($stripe);
    expect($sync->ensurePriceFor($this->plan))->toBe('price_existing_123');
});

test('ensurePriceFor creates a Stripe product + price and persists the id', function () {
    $product = (object) ['id' => 'prod_xyz'];
    $price = (object) ['id' => 'price_xyz'];

    $productsService = Mockery::mock();
    $productsService->shouldReceive('create')->once()->andReturn($product);

    $pricesService = Mockery::mock();
    $pricesService->shouldReceive('create')
        ->once()
        ->withArgs(fn (array $args) => $args['unit_amount'] === 4900
            && $args['recurring']['interval'] === 'month')
        ->andReturn($price);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->products = $productsService;
    $stripe->prices = $pricesService;

    $sync = new StripeProductSync($stripe);
    $result = $sync->ensurePriceFor($this->plan);

    expect($result)->toBe('price_xyz');
    expect($this->plan->fresh()->stripe_price_id)->toBe('price_xyz');
});

test('ensurePriceFor refuses to provision a free or zero-cost plan', function () {
    $free = Plan::query()->updateOrCreate(
        ['slug' => 'free'],
        ['name' => 'Free', 'monthly_conversations' => 100, 'price_cents' => 0, 'features' => [], 'is_active' => true],
    );

    $stripe = Mockery::mock(StripeClient::class);
    $sync = new StripeProductSync($stripe);

    expect(fn () => $sync->ensurePriceFor($free))
        ->toThrow(RuntimeException::class, 'not purchasable');
});
