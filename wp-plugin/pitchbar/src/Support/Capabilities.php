<?php

namespace Pitchbar\Support;

/**
 * Centralizes the capability + nonce checks the settings page and
 * AJAX endpoints rely on. Keeping these in one place avoids drift
 * if the required capability ever changes.
 */
final class Capabilities
{
    public const REQUIRED_CAPABILITY = 'manage_options';

    public const SETTINGS_NONCE_ACTION = 'pitchbar_settings_save';

    public const AJAX_NONCE_ACTION = 'pitchbar_admin_ajax';

    public static function canManage(): bool
    {
        return current_user_can(self::REQUIRED_CAPABILITY);
    }

    public static function abortIfCannotManage(): void
    {
        if (! self::canManage()) {
            wp_die(
                esc_html__('You do not have permission to manage Pitchbar settings.', 'pitchbar'),
                esc_html__('Forbidden', 'pitchbar'),
                ['response' => 403]
            );
        }
    }

    public static function verifyAjaxNonce(string $nonce): bool
    {
        return (bool) wp_verify_nonce($nonce, self::AJAX_NONCE_ACTION);
    }
}
