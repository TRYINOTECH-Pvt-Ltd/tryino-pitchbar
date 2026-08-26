<?php

namespace Pitchbar\Widget;

use Pitchbar\Plugin;
use Pitchbar\Support\Hmac;

/**
 * Mints the short-lived signed token the plugin attaches to the widget
 * script tag when a logged-in WooCommerce customer is browsing. The
 * Pitchbar server verifies it inside `/widget/init` and lifts the
 * `wp_user_id` + `email_hash` claims into the issued widget JWT.
 *
 * Wire format (mirror of `App\Services\Widget\ShopperToken`):
 *
 *   base64url({claims_json}).{Hmac::sign(claims_json, shopper_signing_secret)}
 *
 * Returns an empty string when the plugin hasn't picked up a
 * shopper_signing_secret yet (older handshake response, missing
 * config, etc.) so the widget can still load — just without shopper
 * personalisation.
 */
final class ShopperToken
{
    public static function maybeIssueForCurrentUser(): string
    {
        if (! function_exists('is_user_logged_in') || ! is_user_logged_in()) {
            return '';
        }

        $secret = (string) Plugin::instance()->setting('shopper_signing_secret', '');
        if ($secret === '') {
            return '';
        }

        $user = wp_get_current_user();
        if (! $user || (int) $user->ID <= 0) {
            return '';
        }

        $claims = [
            'wp_user_id' => (string) $user->ID,
            'email_hash' => isset($user->user_email) && $user->user_email !== ''
                ? hash('sha256', strtolower(trim((string) $user->user_email)))
                : '',
            'source' => 'wordpress',
        ];

        $json = wp_json_encode($claims);
        if (! is_string($json)) {
            return '';
        }

        $signature = Hmac::sign($secret, $json);

        return self::base64UrlEncode($json).'.'.$signature;
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
