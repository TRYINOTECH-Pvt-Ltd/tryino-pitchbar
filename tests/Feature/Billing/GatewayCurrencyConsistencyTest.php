<?php

/**
 * Regression guard: all three payment gateways MUST read currency
 * from the same `cashier.currency` config key. If anyone adds a
 * per-gateway currency override later (e.g. PAYPAL_CURRENCY env),
 * the same `price_cents` would charge $50 on one gateway and ₹50
 * on another (off by 60x at INR/USD rates). The source-level check
 * pins the invariant.
 */
test('all three gateway product-syncs read currency from cashier.currency', function () {
    $sources = [
        __DIR__.'/../../../app/Services/Billing/StripeProductSync.php',
        __DIR__.'/../../../app/Services/Billing/PayPalProductSync.php',
        __DIR__.'/../../../app/Services/Billing/RazorpayProductSync.php',
    ];

    foreach ($sources as $path) {
        $content = (string) file_get_contents($path);
        expect($content)->toContain("config('cashier.currency'");
        // No per-gateway currency override key should be referenced.
        expect($content)->not->toContain("config('services.paypal.currency'");
        expect($content)->not->toContain("config('services.razorpay.currency'");
        expect($content)->not->toContain("config('services.stripe.currency'");
    }
});
