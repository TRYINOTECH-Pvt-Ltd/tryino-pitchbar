<?php

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('GET /pricing always includes USD in the currencies list', function () {
    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('marketing/pricing')
                ->has('currency')
                ->has('currencies', fn ($currencies) => $currencies
                    ->where('0.code', 'USD')
                    ->etc()),
        );
});

test('GET /pricing?currency=EUR snaps unknown currencies back to USD', function () {
    $this->get('/pricing?currency=XYZ')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('currency', 'USD'),
        );
});

test('GET /pricing hides plans marked show_on_pricing_page=false', function () {
    Plan::factory()->create([
        'name' => 'Public Plan',
        'price_cents' => 4900,
        'is_active' => true,
        'show_on_pricing_page' => true,
    ]);
    Plan::factory()->create([
        'name' => 'Custom Hidden',
        'price_cents' => 0,
        'is_active' => true,
        'show_on_pricing_page' => false,
    ]);

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('plans', function ($plans) {
                $names = collect($plans)->pluck('name')->all();
                expect($names)->toContain('Public Plan');
                expect($names)->not->toContain('Custom Hidden');

                return true;
            }));
});

test('GET /pricing exposes currencies a DB Plan ships prices for', function () {
    Plan::factory()->create([
        'name' => 'Standard',
        'is_active' => true,
        'billing_model' => Plan::BILLING_SUBSCRIPTION,
        'price_cents' => 4900,
        'prices' => ['usd' => 4900, 'eur' => 4500, 'inr' => 399900],
        'interval' => 'month',
    ]);

    $response = $this->get('/pricing');
    $response->assertOk();

    $codes = collect($response->viewData('page')['props']['currencies'])
        ->pluck('code')
        ->all();

    expect($codes)->toContain('USD', 'EUR', 'INR');
});

test('GET /pricing renders lifetime_plans for billing_model=lifetime', function () {
    Plan::factory()->create([
        'name' => 'Lifetime',
        'slug' => 'lifetime',
        'is_active' => true,
        'billing_model' => Plan::BILLING_LIFETIME,
        'price_cents' => 49900,
        'prices' => ['usd' => 49900],
        'features' => ['Pay once', 'No renewals'],
        'monthly_conversations' => 1000,
    ]);

    $response = $this->get('/pricing');
    $response->assertOk();

    $lifetime = $response->viewData('page')['props']['lifetime_plans'];
    expect($lifetime)->toHaveCount(1);
    expect($lifetime[0]['name'])->toBe('Lifetime');
    expect((float) $lifetime[0]['price'])->toBe(499.0);
    expect($lifetime[0]['currency'])->toBe('USD');
    expect($lifetime[0]['monthly_conversations'])->toBe(1000);
});

test('GET /pricing omits lifetime_plans when none are active', function () {
    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('lifetime_plans', []),
        );
});

test('GET /pricing renders empty plan list when no DB plans exist', function () {
    Plan::query()->delete();

    $response = $this->get('/pricing');
    $response->assertOk();

    $plans = $response->viewData('page')['props']['plans'];
    expect($plans)->toBeArray();
    expect($plans)->toBeEmpty();
});

test('GET /pricing renders DB plans with admin-set features.bullets', function () {
    Plan::query()->delete();
    Plan::factory()->create([
        'name' => 'Standard',
        'slug' => 'standard',
        'is_active' => true,
        'billing_model' => Plan::BILLING_SUBSCRIPTION,
        'monthly_conversations' => 500,
        'price_cents' => 4900,
        'features' => [
            'bullets' => ['Five published agents', 'Priority email support'],
            'tagline' => 'For real traffic.',
            'volume' => '500 conversations / month',
            'cta_label' => 'Start trial',
            'highlight' => true,
        ],
    ]);

    $response = $this->get('/pricing');
    $response->assertOk();

    $plans = $response->viewData('page')['props']['plans'];
    expect($plans)->toHaveCount(1);
    expect($plans[0]['name'])->toBe('Standard');
    expect($plans[0]['features'])->toBe(['Five published agents', 'Priority email support']);
    expect($plans[0]['tagline'])->toBe('For real traffic.');
    expect($plans[0]['highlight'])->toBeTrue();
    expect((float) $plans[0]['monthly_price'])->toBe(49.0);
    expect((float) $plans[0]['yearly_price'])->toBe(0.0);
});

test('GET /pricing surfaces yearly price only when admin set prices.*_yearly key', function () {
    Plan::query()->delete();
    Plan::factory()->create([
        'name' => 'Pro',
        'slug' => 'pro',
        'is_active' => true,
        'billing_model' => Plan::BILLING_SUBSCRIPTION,
        'monthly_conversations' => 3000,
        'price_cents' => 24900,
        'prices' => ['usd' => 24900, 'usd_yearly' => 249000],
        'features' => ['bullets' => []],
    ]);

    $response = $this->get('/pricing');
    $plans = $response->viewData('page')['props']['plans'];

    expect((float) $plans[0]['monthly_price'])->toBe(249.0);
    expect((float) $plans[0]['yearly_price'])->toBe(2490.0);
});

test('GET /pricing?currency=EUR maps prices through DB Plan prices map', function () {
    Plan::factory()->create([
        'name' => 'Standard',
        'is_active' => true,
        'billing_model' => Plan::BILLING_SUBSCRIPTION,
        'price_cents' => 4900,
        'prices' => ['usd' => 4900, 'eur' => 4500],
        'interval' => 'month',
    ]);

    $response = $this->get('/pricing?currency=EUR');
    $response->assertOk();

    $props = $response->viewData('page')['props'];
    expect($props['currency'])->toBe('EUR');
    $standard = collect($props['plans'])->firstWhere('name', 'Standard');
    expect((float) $standard['monthly_price'])->toBe(45.0);
});
