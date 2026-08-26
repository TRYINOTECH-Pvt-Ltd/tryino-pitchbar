<?php

namespace App\Support;

use Illuminate\Container\Container;
use Throwable;

/**
 * Stops a PHP deprecation from turning a normal error into an empty-body 500.
 *
 * THE BUG THIS EXISTS FOR (card #492)
 * An exception escapes an Octane request. Laravel's handler starts rendering
 * its 500 page, which constructs an `Illuminate\Http\Response`. That
 * constructor assigns `$this->headers` directly, and symfony/http-foundation
 * 8.1 turned `$headers` into a property hook that fires `trigger_deprecation`
 * on any direct assignment. Laravel's deprecation handler then tries to log
 * it — on a container that Octane has already flushed:
 *
 *   #11 HandleExceptions.php(105)  LogManager->channel('deprecations')
 *   #5  Application->make('config')
 *   #0  ReflectionException: Class "config" does not exist   -> FATAL
 *
 * The visitor gets HTTP 500 with a zero-byte body, and the *original*
 * exception is never rendered and never logged. The empty-body 500 is a
 * secondary failure that destroys the evidence of the primary one.
 *
 * Laravel's own three guards all fail here, which is why this is needed:
 *  - `shouldIgnoreDeprecationErrors()` checks `hasBeenBootstrapped()`, and
 *    `Application::flush()` does not reset that flag.
 *  - `try { $app->make(LogManager::class) } catch` never fires, because
 *    LogManager is a concrete class the empty container happily reflects into
 *    existence.
 *  - Only the `static::$app['config']` that follows explodes, uncaught.
 *
 * Deliberately version-independent: it keys off "can this container still
 * resolve config?", not off any package version, so a future Laravel or
 * Symfony bump cannot reopen the hole.
 */
final class DeprecationGuard
{
    /**
     * A single bool, not a growing collection — safe to keep across Octane
     * requests. The handler must be installed exactly once per worker: PHP
     * chains error handlers, so re-installing on every request would nest
     * them without bound.
     */
    private static bool $installed = false;

    /** @var callable|null Laravel's handler, which we delegate to. */
    private static $previous = null;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        self::$installed = true;
        self::$previous = set_error_handler(self::handle(...));
    }

    /**
     * Swallow deprecations raised while the container is unusable; pass
     * everything else to the handler we replaced.
     *
     * The return value is deliberately `?bool` and forwarded verbatim. PHP
     * runs its own handler on top only when a handler returns exactly
     * `false`, and Laravel's `handleError()` returns void — so casting the
     * delegated result to bool would turn every deprecation into a second
     * write to error_log.
     */
    public static function handle(int $level, string $message, string $file = '', int $line = 0): ?bool
    {
        $isDeprecation = self::isDeprecation($level);

        // Fast path: we can already see the logger will fail. Returning true
        // tells PHP the error is handled, so it neither reaches Laravel's
        // logger nor PHP's own output. Losing one deprecation notice is the
        // entire cost; losing the response is what we are preventing.
        if ($isDeprecation && ! self::containerIsUsable()) {
            return true;
        }

        if (self::$previous === null) {
            // No handler to delegate to: false is what restores PHP's
            // default behaviour rather than silently swallowing the error.
            return false;
        }

        try {
            /** @var bool|null $handled */
            $handled = (self::$previous)($level, $message, $file, $line);

            return $handled;
        } catch (Throwable $e) {
            // The real guarantee. The fast path above inspects
            // `Container::getInstance()`, but Laravel logs against its own
            // static `HandleExceptions::$app`, and under Octane those are not
            // always the same instance — so the check can pass while the
            // logger still explodes. Whatever the reason, reporting a
            // deprecation must never be allowed to replace the response.
            if ($isDeprecation) {
                return true;
            }

            // Everything else propagates untouched: Laravel converts real
            // errors into ErrorException on purpose, and swallowing those
            // would hide genuine failures.
            throw $e;
        }
    }

    /**
     * Test seam: install on top of a supplied handler instead of whatever PHP
     * currently has registered, so the delegation paths can be exercised
     * without touching the process-wide handler stack. Returns the handler it
     * displaced; callers must pass it back in to restore.
     */
    public static function forwardTo(?callable $previous): ?callable
    {
        $restore = self::$previous;

        self::$installed = true;
        self::$previous = $previous;

        return $restore;
    }

    /**
     * Test seam. Restores PHP's handler stack so a test cannot leak its
     * guard into the rest of the suite.
     */
    public static function uninstall(): void
    {
        if (! self::$installed) {
            return;
        }

        restore_error_handler();

        self::$installed = false;
        self::$previous = null;
    }

    private static function isDeprecation(int $level): bool
    {
        return ($level & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0;
    }

    /**
     * `bound()` only inspects arrays, so it is safe on a flushed container —
     * unlike `make()`, which is exactly what fatals.
     */
    private static function containerIsUsable(): bool
    {
        return Container::getInstance()->bound('config');
    }
}
