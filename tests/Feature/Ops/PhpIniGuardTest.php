<?php

/**
 * Card #491. FrankenPHP's static binary ships WITHOUT a php.ini, so with none
 * present PHP ran on its compile-time defaults — memory_limit=128M and
 * log_errors=0. Under Octane a worker accumulates memory across requests, rode
 * that ceiling, and died mid-request: HTTP 500 with an empty body, a respawned
 * worker, no supervisor restart, and NOTHING logged anywhere because
 * log_errors=0 discarded every fatal. In production that surfaced as
 * recurring all-agent /widget/init 500 bursts.
 *
 * These guards exist because the regression is invisible: delete php.ini and
 * CI stays green, the test suite stays green, and a healthy box stays healthy.
 * The stack just silently goes blind again, and the bursts return weeks later
 * with no trail to follow.
 */

/** @return array<string, string> */
function shippedPhpIni(): array
{
    $parsed = parse_ini_file(base_path('php.ini'));

    expect($parsed)->toBeArray('php.ini exists but could not be parsed.');

    /** @var array<string, string> $parsed */
    return $parsed;
}

test('a php.ini ships with the repo so FrankenPHP never runs on bare defaults', function () {
    expect(file_exists(base_path('php.ini')))->toBeTrue(
        'php.ini is missing. Without it FrankenPHP falls back to memory_limit=128M '.
        'and log_errors=0, and Octane worker fatals become invisible.'
    );
});

test('the shipped php.ini lifts the Octane worker memory ceiling well clear of the default', function () {
    $limit = shippedPhpIni()['memory_limit'] ?? '';

    expect($limit)->not->toBe('', 'php.ini must set memory_limit explicitly.');

    $bytes = (int) $limit * match (strtoupper(substr($limit, -1))) {
        'G' => 1024 ** 3,
        'M' => 1024 ** 2,
        'K' => 1024,
        default => 1,
    };

    // An Octane worker settles around 105-125MB, which is why the 128M
    // compile-time default killed it. 256M is the floor for any headroom
    // at all; we ship 512M.
    expect($bytes)->toBeGreaterThanOrEqual(
        256 * 1024 ** 2,
        "memory_limit is {$limit}; an Octane worker settles near 125MB, so anything under 256M rides the ceiling."
    );
});

test('the shipped php.ini keeps PHP error logging on and pointed at a file', function () {
    $ini = shippedPhpIni();

    expect(filter_var($ini['log_errors'] ?? '0', FILTER_VALIDATE_BOOL))->toBeTrue(
        'log_errors must be On. With it off a worker fatal leaves no trace in any log.'
    );

    expect($ini['error_log'] ?? '')->not->toBe(
        '',
        'error_log must name a file, otherwise fatals go to a SAPI sink nobody reads.'
    );
});

test('the shipped php.ini never exposes internals to visitors', function () {
    expect(filter_var(shippedPhpIni()['display_errors'] ?? '0', FILTER_VALIDATE_BOOL))->toBeFalse();
});
