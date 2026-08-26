<?php

use Pitchbar\Plugin;
use Pitchbar\Settings\SettingsPage;

/**
 * Plugin Name:       Pitchbar
 * Plugin URI:        https://pitchbar.app
 * Description:       Sales-AI chat widget for WordPress and WooCommerce. Connects a WordPress site to a Pitchbar workspace and embeds the streaming chat widget on every public page.
 * Version:           2.0.5
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Pitchbar
 * Author URI:        https://pitchbar.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pitchbar
 * Domain Path:       /languages
 */
if (! defined('ABSPATH')) {
    exit;
}

define('PITCHBAR_PLUGIN_VERSION', '2.0.5');
define('PITCHBAR_PLUGIN_FILE', __FILE__);
define('PITCHBAR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PITCHBAR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PITCHBAR_OPTION_KEY', 'pitchbar_settings');

spl_autoload_register(function ($class) {
    if (strpos($class, 'Pitchbar\\') !== 0) {
        return;
    }
    $relative = substr($class, strlen('Pitchbar\\'));
    $path = PITCHBAR_PLUGIN_DIR.'src/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('pitchbar', false, dirname(plugin_basename(__FILE__)).'/languages');
    Plugin::instance()->boot();
});

register_activation_hook(__FILE__, function () {
    add_option('pitchbar_activation_flag', '1');

    // If the plugin is already configured (re-activation case), queue
    // a one-off full sync 30 seconds out so freshly published content
    // gets indexed without blocking the activation request. WooCommerce
    // product sync, when present, fires 60s out so posts complete first.
    $stored = get_option(PITCHBAR_OPTION_KEY, []);
    if (is_array($stored)
        && ! empty($stored['base_url'])
        && ! empty($stored['api_token'])
        && ! empty($stored['agent_id'])
        && function_exists('wp_schedule_single_event')
    ) {
        if (! wp_next_scheduled(SettingsPage::SCHEDULED_SYNC_HOOK)) {
            wp_schedule_single_event(time() + 30, SettingsPage::SCHEDULED_SYNC_HOOK);
        }

        if (class_exists('WooCommerce')
            && ! wp_next_scheduled(SettingsPage::SCHEDULED_PRODUCT_SYNC_HOOK)
        ) {
            wp_schedule_single_event(time() + 60, SettingsPage::SCHEDULED_PRODUCT_SYNC_HOOK);
        }
    }
});

register_deactivation_hook(__FILE__, function () {
    delete_option('pitchbar_activation_flag');
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook(SettingsPage::SCHEDULED_SYNC_HOOK);
        wp_clear_scheduled_hook(SettingsPage::SCHEDULED_PRODUCT_SYNC_HOOK);
    }
});
