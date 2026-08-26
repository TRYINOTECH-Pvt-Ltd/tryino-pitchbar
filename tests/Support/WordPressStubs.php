<?php

/**
 * Minimal WordPress environment stubs so the plugin's PHP can be
 * instantiated inside Pest tests for round-trip verification of the
 * Hmac + REST contract.
 *
 * Intentionally small — only the functions / classes our plugin code
 * actually references on its hot paths. Anything WC-specific is
 * intercepted at a higher level so we never have to ship a fake
 * WC_Order graph.
 */

namespace Tests\Support;

final class WordPressStubs
{
    public static function install(): void
    {
        if (! defined('ABSPATH')) {
            define('ABSPATH', __DIR__.'/');
        }
        if (! defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }
        if (! defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        if (! defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }
        if (! defined('PITCHBAR_PLUGIN_VERSION')) {
            define('PITCHBAR_PLUGIN_VERSION', '2.0.1');
        }
        if (! defined('PITCHBAR_OPTION_KEY')) {
            define('PITCHBAR_OPTION_KEY', 'pitchbar_settings');
        }

        require_once __DIR__.'/wp-stub-functions.php';
    }

    public static function setOption(string $key, $value): void
    {
        $GLOBALS['__pitchbar_test_options'][$key] = $value;
    }

    public static function resetOptions(): void
    {
        $GLOBALS['__pitchbar_test_options'] = [];
        $GLOBALS['__pitchbar_test_transients'] = [];
    }
}
