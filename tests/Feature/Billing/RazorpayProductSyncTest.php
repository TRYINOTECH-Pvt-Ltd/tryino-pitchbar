<?php

use App\Models\Plan;
use App\Services\Billing\RazorpayClient;
use App\Services\Billing\RazorpayProductSync;
use Illuminate\Support\Facades\Http;

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

    config()->set('services.razorpay.key_id', 'rzp_test_key');
    config()->set('services.razorpay.key_secret', 'test-secret');
});

test('ensurePlanFor reuses a stored razorpay_plan_id without hitting Razorpay', function () {
    $this->plan->forceFill(['razorpay_plan_id' => 'plan_existing_xyz'])->save();

    Http::fake();

    $sync = new RazorpayProductSync(RazorpayClient::fromConfig());

    expect($sync->ensurePlanFor($this->plan))->toBe('plan_existing_xyz');

    Http::assertNothingSent();
});

test('ensurePlanFor creates a Razorpay plan and persists the id', function () {
    Http::fake([
        '*/v1/plans' => Http::response(['id' => 'plan_NEW123', 'item' => ['amount' => 4900]], 200),
    ]);

    $sync = new RazorpayProductSync(RazorpayClient::fromConfig());

    $result = $sync->ensurePlanFor($this->plan);

    expect($result)->toBe('plan_NEW123');
    expect($this->plan->fresh()->razorpay_plan_id)->toBe('plan_NEW123');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains((string) $request->url(), '/v1/plans')
            && ($body['item']['amount'] ?? null) === 4900
            && ($body['period'] ?? null) === 'monthly';
    });
});

test('ensurePlanFor refuses to provision a free plan', function () {
    $free = Plan::query()->updateOrCreate(
        ['slug' => 'free'],
        ['name' => 'Free', 'monthly_conversations' => 100, 'price_cents' => 0, 'features' => [], 'is_active' => true],
    );

    $sync = new RazorpayProductSync(RazorpayClient::fromConfig());

    expect(fn () => $sync->ensurePlanFor($free))
        ->toThrow(RuntimeException::class, 'not purchasable');
});

test('syncPlan throws when Razorpay credentials are missing', function () {
    config()->set('services.razorpay.key_id', '');
    config()->set('services.razorpay.key_secret', '');

    $sync = new RazorpayProductSync(RazorpayClient::fromConfig());

    expect(fn () => $sync->syncPlan($this->plan))
        ->toThrow(RuntimeException::class, 'Razorpay is not configured');
});
