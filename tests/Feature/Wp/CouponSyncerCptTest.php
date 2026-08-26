<?php

use Pitchbar\Plugin;
use Pitchbar\Sync\CouponSyncer;
use Tests\Support\WordPressStubs;

/**
 * Verifies the v2.0.0 CRITICAL #3 fix: CouponSyncer no longer relies
 * on `wc_get_coupons()` (which is NOT public WC API on every release).
 * Instead it enumerates the `shop_coupon` CPT via `get_posts()` and
 * hydrates each match through the public `WC_Coupon` class.
 *
 * This catches the "Connected store, zero coupons synced" regression
 * that bit v1.2.0 on WC <= 3.x and on WC versions where the function
 * was removed/renamed.
 */
beforeEach(function () {
    require_once dirname(__DIR__, 2).'/Support/WordPressStubs.php';
    WordPressStubs::install();
    WordPressStubs::resetOptions();

    $pluginDir = dirname(__DIR__, 2).'/../wp-plugin/pitchbar/';
    spl_autoload_register(function ($class) use ($pluginDir) {
        if (strpos($class, 'Pitchbar\\') !== 0) {
            return;
        }
        $relative = substr($class, strlen('Pitchbar\\'));
        $path = $pluginDir.'src/'.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
        if (is_readable($path)) {
            require_once $path;
        }
    });

    if (class_exists('Pitchbar\\Plugin')) {
        $reflection = new ReflectionClass(Plugin::class);
        $prop = $reflection->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
});

test('CouponSyncer returns woocommerce_inactive when WC_Coupon class is missing', function () {
    WordPressStubs::setOption('pitchbar_settings', [
        'shopper_signing_secret' => 'sek',
        'api_token' => 'pbar_x',
        'base_url' => 'https://app.test',
        'agent_id' => 'agent-id',
    ]);

    // Neither WooCommerce nor WC_Coupon classes exist in the stub
    // harness, so the early-return branch fires.
    $result = (new CouponSyncer)->run();

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toBe('woocommerce_inactive');
});

test('CouponSyncer guards on WC_Coupon, not wc_get_coupons() — v1.2.0 regression', function () {
    // The v1.2.0 implementation gated on `function_exists('wc_get_coupons')`
    // which silently returned false on WC versions where the function
    // is not exposed publicly. The v2.0.0 fix gates on `class_exists('WC_Coupon')`,
    // which IS stable. Smoke-test the gate-string itself by reading the
    // class source — no live WC needed.
    $source = file_get_contents(dirname(__DIR__, 3).'/wp-plugin/pitchbar/src/Sync/CouponSyncer.php');

    expect($source)->toContain("class_exists('WC_Coupon')");
    expect($source)->not()->toContain("function_exists('wc_get_coupons')");
});

test('CouponSyncer enumerates the shop_coupon CPT via get_posts', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/wp-plugin/pitchbar/src/Sync/CouponSyncer.php');

    // The enumeration path must call `get_posts(...)` with
    // `post_type => shop_coupon` so it works on every WC version that
    // ships the CPT (which is every version since WC introduced
    // coupons in 2.0).
    expect($source)->toContain("'post_type' => 'shop_coupon'");
});
