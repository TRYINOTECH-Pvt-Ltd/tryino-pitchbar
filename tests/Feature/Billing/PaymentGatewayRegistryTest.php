<?php

use App\Services\Billing\PaymentGatewayRegistry;

beforeEach(function () {
    config()->set('cashier.enabled', true);
    config()->set('cashier.secret', '');
    config()->set('services.paypal.enabled', false);
    config()->set('services.paypal.client_id', '');
    config()->set('services.paypal.client_secret', '');
    config()->set('services.razorpay.enabled', false);
    config()->set('services.razorpay.key_id', '');
    config()->set('services.razorpay.key_secret', '');
});

test('a gateway is unavailable when disabled even if credentials are present', function () {
    config()->set('cashier.enabled', false);
    config()->set('cashier.secret', 'sk_test_dummy');

    $registry = new PaymentGatewayRegistry;

    expect($registry->isEnabled('stripe'))->toBeFalse();
    expect($registry->isConfigured('stripe'))->toBeTrue();
    expect($registry->isAvailable('stripe'))->toBeFalse();
});

test('a gateway is unavailable when enabled but credentials are missing', function () {
    config()->set('services.paypal.enabled', true);
    // client_id + client_secret stay empty

    $registry = new PaymentGatewayRegistry;

    expect($registry->isEnabled('paypal'))->toBeTrue();
    expect($registry->isConfigured('paypal'))->toBeFalse();
    expect($registry->isAvailable('paypal'))->toBeFalse();
});

test('all three gateways list as enabled when each is configured + flagged on', function () {
    config()->set('cashier.enabled', true);
    config()->set('cashier.secret', 'sk_test_x');
    config()->set('services.paypal.enabled', true);
    config()->set('services.paypal.client_id', 'cid');
    config()->set('services.paypal.client_secret', 'csecret');
    config()->set('services.razorpay.enabled', true);
    config()->set('services.razorpay.key_id', 'rzp_test_x');
    config()->set('services.razorpay.key_secret', 'rzp_secret');

    $registry = new PaymentGatewayRegistry;

    expect($registry->enabledGateways())->toBe(['stripe', 'paypal', 'razorpay']);
    expect($registry->defaultGateway())->toBe('stripe');
});

test('default gateway falls back to first available when stripe is missing', function () {
    config()->set('services.razorpay.enabled', true);
    config()->set('services.razorpay.key_id', 'rzp_test_x');
    config()->set('services.razorpay.key_secret', 'rzp_secret');

    $registry = new PaymentGatewayRegistry;

    expect($registry->enabledGateways())->toBe(['razorpay']);
    expect($registry->defaultGateway())->toBe('razorpay');
});

test('default gateway falls back to stripe when nothing is configured', function () {
    $registry = new PaymentGatewayRegistry;

    expect($registry->enabledGateways())->toBe([]);
    expect($registry->defaultGateway())->toBe('stripe');
});
