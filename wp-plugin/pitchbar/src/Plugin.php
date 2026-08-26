<?php

namespace Pitchbar;

use Pitchbar\Admin\SyncStatusColumn;
use Pitchbar\Rest\CartCouponController;
use Pitchbar\Rest\LeadController;
use Pitchbar\Rest\OrderLookupController;
use Pitchbar\Settings\SettingsPage;
use Pitchbar\Sync\DeltaListener;
use Pitchbar\Sync\ProductDeltaListener;
use Pitchbar\Widget\Embed;

/**
 * Plugin singleton. Holds the in-process pointer to the loaded
 * settings array and wires up admin + front-end hooks.
 */
final class Plugin
{
    /** @var Plugin|null */
    private static $instance = null;

    /** @var array<string, mixed>|null */
    private $settings = null;

    /** @var SettingsPage|null */
    private $settingsPage = null;

    /** @var Embed|null */
    private $embed = null;

    /** @var DeltaListener|null */
    private $deltaListener = null;

    /** @var ProductDeltaListener|null */
    private $productDeltaListener = null;

    /** @var OrderLookupController|null */
    private $orderLookup = null;

    /** @var LeadController|null */
    private $leadController = null;

    /** @var CartCouponController|null */
    private $cartCouponController = null;

    /** @var SyncStatusColumn|null */
    private $syncStatusColumn = null;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function boot(): void
    {
        // Core (works without WooCommerce). Registered immediately.
        $this->settingsPage = new SettingsPage;
        $this->settingsPage->register();

        $this->embed = new Embed;
        $this->embed->register();

        $this->deltaListener = new DeltaListener;
        $this->deltaListener->register();

        $this->orderLookup = new OrderLookupController;
        $this->orderLookup->register();

        $this->leadController = new LeadController;
        $this->leadController->register();

        $this->syncStatusColumn = new SyncStatusColumn;
        $this->syncStatusColumn->register();

        // WooCommerce-dependent surface — DO NOT trust
        // `class_exists('WooCommerce')` at `plugins_loaded` priority 10,
        // because alphabetical plugin load order puts `pitchbar` BEFORE
        // `woocommerce` on most sites. The class doesn't exist yet at
        // priority 10 → WC features silently disable. Two-layer guard:
        //
        //   1. Listen to `woocommerce_loaded` — fires after WC has
        //      registered its main class. Idempotent.
        //   2. If WC is somehow already loaded by the time `boot()`
        //      runs (priority-20+ plugins, manual instantiation), wire
        //      up immediately so we don't miss the action.
        //
        // `did_action('woocommerce_loaded')` returns > 0 only after the
        // action already fired, so the guard catches both code paths.
        if (function_exists('did_action') && did_action('woocommerce_loaded') > 0) {
            $this->bootWoo();
        } elseif (function_exists('add_action')) {
            add_action('woocommerce_loaded', [$this, 'bootWoo'], 10);
        }
    }

    /**
     * Registers every hook + controller that calls into the WooCommerce
     * API. Safe to call multiple times — both registrations check
     * their own already-bound state. Public so the `woocommerce_loaded`
     * action can invoke it directly.
     */
    public function bootWoo(): void
    {
        if ($this->productDeltaListener !== null) {
            return; // Already wired.
        }
        if (! class_exists('WooCommerce')) {
            return; // Action fired but WC class missing — give up.
        }

        $this->productDeltaListener = new ProductDeltaListener;
        $this->productDeltaListener->register();

        $this->cartCouponController = new CartCouponController;
        $this->cartCouponController->register();

        $this->registerCartStateScript();
    }

    /**
     * Enqueues the front-end script that mirrors WooCommerce cart
     * events into localStorage so the widget's abandoned_cart trigger
     * can read the visitor's cart age + size without a server hit.
     */
    private function registerCartStateScript(): void
    {
        add_action('wp_enqueue_scripts', function () {
            if (! $this->isConfigured()) {
                return;
            }
            wp_enqueue_script(
                'pitchbar-cart-state',
                PITCHBAR_PLUGIN_URL.'assets/cart-state.js',
                [],
                PITCHBAR_PLUGIN_VERSION,
                true
            );
        });

        // Stash the visitor's Pitchbar conversation id in a cookie so
        // CartCouponController::applyPendingCoupon can locate their
        // pending coupon on the next cart load.
        add_action('init', function () {
            if (is_admin() || headers_sent()) {
                return;
            }
            if (empty($_COOKIE['pitchbar_conv_id']) && isset($_GET['pitchbar_conv'])) {
                $value = sanitize_text_field(wp_unslash((string) $_GET['pitchbar_conv']));
                if ($value !== '') {
                    setcookie('pitchbar_conv_id', $value, [
                        'expires' => time() + DAY_IN_SECONDS,
                        'path' => '/',
                        'samesite' => 'Lax',
                        'secure' => is_ssl(),
                    ]);
                }
            }
        });
    }

    /**
     * Returns the full settings array with defaults filled in. Cached
     * for the request because WP's `get_option` hits the db each time.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        if ($this->settings === null) {
            $stored = get_option(PITCHBAR_OPTION_KEY, []);
            if (! is_array($stored)) {
                $stored = [];
            }
            $this->settings = array_merge($this->defaults(), $stored);
        }

        return $this->settings;
    }

    public function setting(string $key, $fallback = null)
    {
        $all = $this->settings();

        return array_key_exists($key, $all) ? $all[$key] : $fallback;
    }

    public function refreshSettingsCache(): void
    {
        $this->settings = null;
    }

    public function isConfigured(): bool
    {
        $baseUrl = (string) $this->setting('base_url', '');
        $token = (string) $this->setting('api_token', '');
        $agentId = (string) $this->setting('agent_id', '');

        return $baseUrl !== '' && $token !== '' && $agentId !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'base_url' => '',
            'api_token' => '',
            'agent_id' => '',
            'workspace_id' => '',
            'workspace_name' => '',
            'enabled' => true,
            'enabled_post_types' => ['post', 'page'],
            'shopper_signing_secret' => '',
        ];
    }
}
