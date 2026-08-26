<?php

namespace Pitchbar\Support;

/**
 * Thin wrapper around `error_log` so plugin code can log without
 * littering WP-CLI output in non-debug environments.
 *
 * Active only when `WP_DEBUG` is true. Otherwise every call is a no-op.
 */
final class Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        $line = sprintf('[Pitchbar][%s] %s', $level, $message);
        if (! empty($context)) {
            $encoded = wp_json_encode($context);
            if (is_string($encoded)) {
                $line .= ' '.$encoded;
            }
        }

        error_log($line);
    }
}
