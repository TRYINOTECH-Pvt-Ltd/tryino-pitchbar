<?php

use App\Providers\AppServiceProvider;
use Laravel\Cashier\Cashier;

afterEach(function () {
    // Cashier's flag is a static — reset so an enabled-flag test can
    // never leak tax behavior into unrelated billing tests.
    Cashier::$calculatesTaxes = false;
});

test('automatic tax is OFF by default — no behavior change for existing installs', function () {
    // The suite boots with CASHIER_AUTO_TAX unset, so the provider's
    // gate must have left Cashier untouched.
    expect((bool) config('services.stripe_tax.enabled'))->toBeFalse()
        ->and(Cashier::$calculatesTaxes)->toBeFalse();
});

test('CASHIER_AUTO_TAX=true enables Cashier automatic tax calculation at boot', function () {
    config(['services.stripe_tax.enabled' => true]);

    // Re-run the provider's boot — the gate reads the config and flips
    // Cashier::$calculatesTaxes, which Cashier then applies to every
    // new subscription, one-off invoice, and Checkout session payload
    // (automatic_tax + tax-ID collection).
    (new AppServiceProvider(app()))->boot();

    expect(Cashier::$calculatesTaxes)->toBeTrue();
});
