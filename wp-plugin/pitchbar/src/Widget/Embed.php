<?php

namespace Pitchbar\Widget;

use Pitchbar\Plugin;

/**
 * Injects the Pitchbar widget loader into the page footer on every
 * front-end request when:
 *   - the plugin is configured (base URL + token + agent),
 *   - the widget is enabled,
 *   - the current request is not an admin / login / REST / cron / AJAX path,
 *   - the current post type is one the admin opted into.
 *
 * Page-context is passed as a JSON blob in `data-page-context` so the
 * widget can skip DOM scraping for retrieval grounding.
 */
final class Embed
{
    /** @var PageContext */
    private $pageContext;

    public function __construct(?PageContext $pageContext = null)
    {
        $this->pageContext = $pageContext ?? new PageContext;
    }

    public function register(): void
    {
        add_action('wp_footer', [$this, 'render'], 99);
    }

    public function render(): void
    {
        if (! $this->shouldRender()) {
            return;
        }

        $plugin = Plugin::instance();
        $baseUrl = rtrim((string) $plugin->setting('base_url'), '/');
        $agentId = (string) $plugin->setting('agent_id');

        $context = $this->pageContext->collect();
        $contextJson = wp_json_encode($context);
        if ($contextJson === false) {
            $contextJson = '{}';
        }

        $scriptSrc = $baseUrl.'/widget/widget.js';

        $shopperToken = ShopperToken::maybeIssueForCurrentUser();
        $shopperAttr = $shopperToken !== ''
            ? ' data-shopper-token="'.esc_attr($shopperToken).'"'
            : '';

        // Tell the widget the document direction so the bar mirrors
        // correctly on RTL locales (Arabic, Hebrew, Persian, Urdu).
        // `is_rtl()` reads the active locale and any per-user override.
        $dir = (function_exists('is_rtl') && is_rtl()) ? 'rtl' : 'ltr';
        $locale = function_exists('determine_locale') ? (string) determine_locale() : (function_exists('get_locale') ? (string) get_locale() : '');

        printf(
            '<script async src="%s" data-agent="%s" data-page-context="%s" data-page-dir="%s" data-page-locale="%s"%s></script>',
            esc_url($scriptSrc),
            esc_attr($agentId),
            esc_attr($contextJson),
            esc_attr($dir),
            esc_attr($locale),
            $shopperAttr
        );
    }

    private function shouldRender(): bool
    {
        $plugin = Plugin::instance();
        if (! $plugin->isConfigured()) {
            return false;
        }
        if (! $plugin->setting('enabled', true)) {
            return false;
        }
        if (is_admin() || $this->isLoginPage() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return false;
        }

        if (is_singular()) {
            $enabled = (array) $plugin->setting('enabled_post_types', ['post', 'page']);
            $current = (string) get_post_type();
            if ($current !== '' && ! in_array($current, $enabled, true)) {
                return false;
            }
        }

        return true;
    }

    private function isLoginPage(): bool
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        if ($script === '') {
            return false;
        }

        return strpos($script, 'wp-login.php') !== false || strpos($script, 'wp-register.php') !== false;
    }
}
