<?php

use App\Support\DeprecationGuard;
use Illuminate\Container\Container;

/**
 * Card #492. An exception escaping an Octane request made Laravel render its
 * 500 page; constructing the Response emitted a symfony/http-foundation 8.1
 * deprecation; logging that deprecation resolved `config` on a container
 * Octane had already flushed, and the uncaught ReflectionException replaced
 * the whole response with a zero-byte 500. The original exception was never
 * rendered and never logged.
 *
 * The guard's whole job is the first test below: on a container that can no
 * longer resolve `config`, a deprecation must be swallowed rather than
 * allowed to reach a logger that will fatal.
 */
function withFlushedContainer(callable $body): mixed
{
    $real = Container::getInstance();

    $flushed = new Container;
    $flushed->flush();
    Container::setInstance($flushed);

    try {
        return $body();
    } finally {
        Container::setInstance($real);
    }
}

test('a deprecation raised on a flushed container is swallowed, not logged', function () {
    $handled = withFlushedContainer(
        fn () => DeprecationGuard::handle(E_USER_DEPRECATED, 'Since symfony/http-foundation 8.1: Directly setting property "headers"')
    );

    // true = "handled", so PHP neither runs its own handler nor lets Laravel's
    // reach LogManager -> $app['config'] -> ReflectionException -> fatal.
    expect($handled)->toBeTrue();
});

test('the swallow path never touches the container beyond a binding lookup', function () {
    // `bound()` only inspects arrays; `make()` is what fatals. If the guard
    // ever reaches for the config repository itself this throws.
    withFlushedContainer(function () {
        DeprecationGuard::handle(E_DEPRECATED, 'anything at all');
        DeprecationGuard::handle(E_USER_DEPRECATED, 'anything at all');
    });
})->throwsNoExceptions();

test('a deprecation on a healthy container is delegated to Laravel untouched', function () {
    expect(Container::getInstance()->bound('config'))->toBeTrue();

    // Laravel's handleError() returns void for deprecations. PHP only runs its
    // own handler when a handler returns exactly false, so forwarding that
    // null verbatim is what keeps a single deprecation from being written
    // twice. A bool cast here would regress that.
    expect(DeprecationGuard::handle(E_USER_DEPRECATED, 'a real deprecation'))->toBeNull();
});

test('a non-deprecation error is always delegated, even on a flushed container', function () {
    // The guard must not become a general-purpose error swallower: only
    // deprecations are safe to drop, and only while the container is dead.
    // Laravel turns this into an ErrorException, which is exactly what should
    // still happen.
    withFlushedContainer(
        fn () => DeprecationGuard::handle(E_USER_WARNING, 'a warning that must not be silenced')
    );
})->throws(ErrorException::class, 'a warning that must not be silenced');

test('a logger that explodes while reporting a deprecation cannot take the response with it', function () {
    // The fast path inspects Container::getInstance(), but Laravel logs
    // against its own static HandleExceptions::$app — under Octane those are
    // not always the same instance, so the check can pass while the logger
    // still fatals. This is the path that actually guarantees the fix.
    $restore = DeprecationGuard::forwardTo(function () {
        throw new ReflectionException('Class "config" does not exist');
    });

    try {
        expect(DeprecationGuard::handle(E_USER_DEPRECATED, 'a deprecation nobody can log'))->toBeTrue();
    } finally {
        DeprecationGuard::forwardTo($restore);
    }
});

test('a throwing handler still propagates for anything that is not a deprecation', function () {
    $restore = DeprecationGuard::forwardTo(function () {
        throw new ErrorException('a genuine failure');
    });

    try {
        expect(fn () => DeprecationGuard::handle(E_USER_WARNING, 'a genuine failure'))
            ->toThrow(ErrorException::class, 'a genuine failure');
    } finally {
        DeprecationGuard::forwardTo($restore);
    }
});

test('install is idempotent so Octane cannot nest handlers across requests', function () {
    // AppServiceProvider::boot() already installed it for this test app.
    // PHP chains error handlers, so a second install would stack another
    // frame on every request until the worker recycles.
    $before = set_error_handler(null);
    restore_error_handler();

    DeprecationGuard::install();
    DeprecationGuard::install();

    $after = set_error_handler(null);
    restore_error_handler();

    expect($after)->toBe($before);
});
