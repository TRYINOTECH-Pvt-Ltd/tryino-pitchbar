<?php

use App\Models\Plan;
use App\Services\Billing\CurrencyCatalog;
use App\Services\Billing\CurrencyResolver;
use Illuminate\Http\Request;

test('CurrencyCatalog exposes 50+ ISO 4217 codes', function () {
    $all = CurrencyCatalog::all();
    expect(count($all))->toBeGreaterThanOrEqual(50);

    $byCode = CurrencyCatalog::indexedByCode();
    expect($byCode)
        ->toHaveKey('usd')
        ->toHaveKey('eur')
        ->toHaveKey('gbp')
        ->toHaveKey('inr')
        ->toHaveKey('bdt')
        ->toHaveKey('jpy');
});

test('JPY + KRW + VND + ISK + CLP carry zero decimal places', function () {
    expect(CurrencyCatalog::decimalPlacesFor('JPY'))->toBe(0);
    expect(CurrencyCatalog::decimalPlacesFor('KRW'))->toBe(0);
    expect(CurrencyCatalog::decimalPlacesFor('VND'))->toBe(0);
    expect(CurrencyCatalog::decimalPlacesFor('ISK'))->toBe(0);
    expect(CurrencyCatalog::decimalPlacesFor('CLP'))->toBe(0);
});

test('Plan priceFor returns the requested currency, falling back to USD then price_cents', function () {
    $plan = Plan::factory()->create([
        'price_cents' => 4900,
        'prices' => ['usd' => 4900, 'eur' => 4500, 'inr' => 399000],
    ]);

    expect($plan->priceFor('USD'))->toBe(4900);
    expect($plan->priceFor('EUR'))->toBe(4500);
    expect($plan->priceFor('INR'))->toBe(399000);
    // Unknown currency falls back to USD.
    expect($plan->priceFor('XYZ'))->toBe(4900);
});

test('Plan priceFor falls back to legacy price_cents when prices map is empty', function () {
    $plan = Plan::factory()->create([
        'price_cents' => 4900,
        'prices' => null,
    ]);

    expect($plan->priceFor('USD'))->toBe(4900);
    expect($plan->priceFor('EUR'))->toBe(4900);
});

test('Plan availableCurrencies returns the keys of the prices map', function () {
    $plan = Plan::factory()->create([
        'prices' => ['usd' => 4900, 'eur' => 4500, 'gbp' => 3900],
    ]);

    expect($plan->availableCurrencies())
        ->toContain('usd')
        ->toContain('eur')
        ->toContain('gbp')
        ->toHaveCount(3);
});

test('setStripePriceIdFor mirrors USD into the legacy column for Cashier compatibility', function () {
    $plan = Plan::factory()->create(['stripe_price_id' => null, 'stripe_price_ids' => null]);

    $plan->setStripePriceIdFor('usd', 'price_usd_xxx');
    $plan->setStripePriceIdFor('eur', 'price_eur_yyy');

    $plan->refresh();
    expect($plan->stripePriceIdFor('usd'))->toBe('price_usd_xxx');
    expect($plan->stripePriceIdFor('eur'))->toBe('price_eur_yyy');
    expect($plan->stripe_price_id)->toBe('price_usd_xxx');
    expect($plan->stripePriceIdFor('gbp'))->toBeNull();
});

test('CurrencyResolver honors an explicit ?currency=eur query string', function () {
    $resolver = new CurrencyResolver;
    $request = Request::create('/pricing?currency=eur', 'GET');

    $code = $resolver->resolve($request, ['usd', 'eur', 'gbp']);
    expect($code)->toBe('eur');
});

test('CurrencyResolver ignores a requested currency the plan does not support', function () {
    $resolver = new CurrencyResolver;
    $request = Request::create('/pricing?currency=xyz', 'GET');

    $code = $resolver->resolve($request, ['usd', 'eur']);
    // Falls through to defaults.
    expect($code)->toBe('usd');
});

test('CurrencyResolver maps CF-IPCountry to a sensible currency', function () {
    $resolver = new CurrencyResolver;

    $request = Request::create('/pricing', 'GET', server: ['HTTP_CF_IPCOUNTRY' => 'IN']);
    expect($resolver->resolve($request, ['usd', 'inr', 'eur']))->toBe('inr');

    $request = Request::create('/pricing', 'GET', server: ['HTTP_CF_IPCOUNTRY' => 'DE']);
    expect($resolver->resolve($request, ['usd', 'eur']))->toBe('eur');

    $request = Request::create('/pricing', 'GET', server: ['HTTP_CF_IPCOUNTRY' => 'JP']);
    expect($resolver->resolve($request, ['usd', 'jpy']))->toBe('jpy');
});

test('CurrencyResolver maps Accept-Language to a sensible currency', function () {
    $resolver = new CurrencyResolver;

    $request = Request::create('/pricing', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'bn-BD,bn;q=0.9,en;q=0.8']);
    expect($resolver->resolve($request, ['usd', 'bdt']))->toBe('bdt');

    $request = Request::create('/pricing', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'zh-CN,zh;q=0.9']);
    expect($resolver->resolve($request, ['usd', 'cny']))->toBe('cny');
});

test('CurrencyResolver falls back to USD when nothing matches', function () {
    $resolver = new CurrencyResolver;
    $request = Request::create('/pricing', 'GET');

    expect($resolver->resolve($request, ['usd', 'eur']))->toBe('usd');
});

test('CurrencyResolver returns the first available currency when USD is not in the plan', function () {
    $resolver = new CurrencyResolver;
    $request = Request::create('/pricing', 'GET');

    expect($resolver->resolve($request, ['inr', 'bdt']))->toBe('inr');
});

test('Existing legacy USD plans keep working without any prices JSON', function () {
    // Backwards-compat regression. Earlier releases shipped only
    // `price_cents`; the multi-currency reader must not break those.
    $plan = Plan::factory()->create([
        'price_cents' => 9900,
        'prices' => null,
        'stripe_price_id' => 'price_legacy_xyz',
        'stripe_price_ids' => null,
    ]);

    expect($plan->priceFor('usd'))->toBe(9900);
    expect($plan->stripePriceIdFor('usd'))->toBe('price_legacy_xyz');
});
