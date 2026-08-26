<?php

use App\Support\HmacSignature;
use Pitchbar\Support\Hmac;

/**
 * Battle-test: load the WordPress plugin's `Pitchbar\Support\Hmac`
 * helper into the same PHP process as Pitchbar's `App\Support\HmacSignature`
 * and verify that signatures produced on one side round-trip through
 * the other. Catches drift between the two implementations BEFORE a
 * real WP install ever sees a request.
 */
beforeEach(function () {
    if (! class_exists('Pitchbar\\Support\\Hmac')) {
        require_once dirname(__DIR__, 2).'/../wp-plugin/pitchbar/src/Support/Hmac.php';
    }
});

test('Pitchbar server signature verifies via plugin Hmac::verify', function () {
    $secret = 'pbar_signing_secret_'.bin2hex(random_bytes(16));
    $body = '{"hello":"world","unicode":"éclair"}';

    $header = HmacSignature::sign($secret, $body);

    expect(Hmac::verify($header, $secret, $body))->toBeTrue();
});

test('Plugin signature verifies via Pitchbar server HmacSignature::verify', function () {
    $secret = 'pbar_signing_secret_'.bin2hex(random_bytes(16));
    $body = '{"agent_id":"abc","action":"upsert"}';

    $header = Hmac::sign($secret, $body);

    expect(HmacSignature::verify($header, $secret, $body))->toBeTrue();
});

test('Cross-implementation: server-signed body fails plugin verify with a different secret', function () {
    $body = 'body';
    $header = HmacSignature::sign('secret-a', $body);

    expect(Hmac::verify($header, 'secret-b', $body))->toBeFalse();
});

test('Cross-implementation: plugin-signed expired-timestamp fails server verify', function () {
    $secret = 'pbar_signing_secret_'.bin2hex(random_bytes(16));
    $body = 'body';
    $oldTimestamp = time() - HmacSignature::REPLAY_WINDOW_SECONDS - 60;

    $header = Hmac::sign($secret, $body, $oldTimestamp);

    expect(HmacSignature::verify($header, $secret, $body))->toBeFalse();
});

test('Replay window constants match between server and plugin', function () {
    expect(HmacSignature::REPLAY_WINDOW_SECONDS)
        ->toBe(Hmac::REPLAY_WINDOW_SECONDS);
});

test('A 1-byte body change invalidates the cross-implementation signature', function () {
    $secret = 'pbar_signing_secret';
    $body = '{"k":"v"}';
    $header = HmacSignature::sign($secret, $body);

    expect(Hmac::verify($header, $secret, '{"k":"V"}'))->toBeFalse();
});
