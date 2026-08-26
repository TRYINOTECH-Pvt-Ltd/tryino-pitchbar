<?php

use App\Support\HmacSignature;

test('sign produces a parseable header', function () {
    $header = HmacSignature::sign('secret', '{"hello":"world"}', timestamp: 1_700_000_000);

    expect($header)->toMatch('/^t=1700000000,v1=[a-f0-9]{64}$/');
});

test('verify accepts a freshly signed body', function () {
    $body = '{"hello":"world"}';
    $now = 1_700_000_000;
    $header = HmacSignature::sign('secret', $body, $now);

    expect(HmacSignature::verify($header, 'secret', $body, $now))->toBeTrue();
});

test('verify rejects a body that has been tampered with', function () {
    $now = 1_700_000_000;
    $header = HmacSignature::sign('secret', 'original', $now);

    expect(HmacSignature::verify($header, 'secret', 'tampered', $now))->toBeFalse();
});

test('verify rejects a different secret', function () {
    $now = 1_700_000_000;
    $header = HmacSignature::sign('alpha', 'body', $now);

    expect(HmacSignature::verify($header, 'beta', 'body', $now))->toBeFalse();
});

test('verify rejects a signature outside the replay window', function () {
    $signed = 1_700_000_000;
    $tooLate = $signed + HmacSignature::REPLAY_WINDOW_SECONDS + 1;
    $header = HmacSignature::sign('secret', 'body', $signed);

    expect(HmacSignature::verify($header, 'secret', 'body', $tooLate))->toBeFalse();
});

test('verify accepts a signature on the boundary of the replay window', function () {
    $signed = 1_700_000_000;
    $atEdge = $signed + HmacSignature::REPLAY_WINDOW_SECONDS;
    $header = HmacSignature::sign('secret', 'body', $signed);

    expect(HmacSignature::verify($header, 'secret', 'body', $atEdge))->toBeTrue();
});

test('verify rejects a malformed header', function () {
    expect(HmacSignature::verify('garbage', 'secret', 'body'))->toBeFalse();
    expect(HmacSignature::verify('t=abc,v1=def', 'secret', 'body'))->toBeFalse();
    expect(HmacSignature::verify('t=1700000000', 'secret', 'body'))->toBeFalse();
});

test('verify rejects a signature with wrong hex length', function () {
    expect(HmacSignature::verify('t=1700000000,v1=abc', 'secret', 'body'))->toBeFalse();
});
