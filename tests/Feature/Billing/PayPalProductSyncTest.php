<?php

use App\Models\Plan;
use App\Services\Billing\PayPalClient;
use App\Services\Billing\PayPalProductSync;
use Illuminate\Support\Facades\Cache;
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

    config()->set('services.paypal.mode', 'sandbox');
    config()->set('services.paypal.client_id', 'test-client-id');
    config()->set('services.paypal.client_secret', 'test-client-secret');

    Cache::flush();
});

test('ensurePlanFor reuses an already-saved paypal_plan_id without hitting PayPal', function () {
    $this->plan->forceFill(['paypal_plan_id' => 'P-EXISTING-PLAN'])->save();

    Http::fake();

    $sync = new PayPalProductSync(PayPalClient::fromConfig());

    expect($sync->ensurePlanFor($this->plan))->toBe('P-EXISTING-PLAN');

    Http::assertNothingSent();
});

test('ensurePlanFor creates a PayPal product + plan and persists both ids', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 32400], 200),
        '*/v1/catalogs/products' => Http::response(['id' => 'PROD-123'], 201),
        '*/v1/billing/plans' => Http::response(['id' => 'P-PLAN-XYZ', 'status' => 'ACTIVE'], 201),
    ]);

    $sync = new PayPalProductSync(PayPalClient::fromConfig());

    $result = $sync->ensurePlanFor($this->plan);

    expect($result)->toBe('P-PLAN-XYZ');
    $fresh = $this->plan->fresh();
    expect($fresh->paypal_product_id)->toBe('PROD-123');
    expect($fresh->paypal_plan_id)->toBe('P-PLAN-XYZ');
});

test('ensurePlanFor refuses to provision a free plan', function () {
    $free = Plan::query()->updateOrCreate(
        ['slug' => 'free'],
        ['name' => 'Free', 'monthly_conversations' => 100, 'price_cents' => 0, 'features' => [], 'is_active' => true],
    );

    $sync = new PayPalProductSync(PayPalClient::fromConfig());

    expect(fn () => $sync->ensurePlanFor($free))
        ->toThrow(RuntimeException::class, 'not purchasable');
});

test('syncPlan throws when PayPal credentials are missing', function () {
    config()->set('services.paypal.client_id', '');
    config()->set('services.paypal.client_secret', '');

    $sync = new PayPalProductSync(PayPalClient::fromConfig());

    expect(fn () => $sync->syncPlan($this->plan))
        ->toThrow(RuntimeException::class, 'PayPal is not configured');
});

test('archivePlan deactivates an existing PayPal plan and swallows errors', function () {
    $this->plan->forceFill(['paypal_plan_id' => 'P-ARCHIVE-ME'])->save();

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 32400], 200),
        '*/v1/billing/plans/P-ARCHIVE-ME/deactivate' => Http::response(null, 204),
    ]);

    $sync = new PayPalProductSync(PayPalClient::fromConfig());

    $sync->archivePlan($this->plan);

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'P-ARCHIVE-ME/deactivate'));
});
