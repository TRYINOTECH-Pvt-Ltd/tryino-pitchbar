<?php

/**
 * Fired when the plugin is uninstalled via the WordPress dashboard.
 * Removes every option Pitchbar wrote so a reinstall starts clean.
 */
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('pitchbar_settings');
delete_option('pitchbar_activation_flag');
delete_site_option('pitchbar_settings');
delete_site_option('pitchbar_activation_flag');
