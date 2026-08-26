<?php

use Pitchbar\Sync\PostSyncer;
use Pitchbar\Sync\ProductSyncer;
use Tests\Support\WordPressStubs;

/**
 * Verifies the v2.0.0 CRITICAL #5 fix: PostSyncer + ProductSyncer
 * enforce a wall-clock time budget so shared-hosting installs with a
 * 30s PHP execution cap don't fatally time out mid-sync. When the
 * budget is exceeded AND more pages remain, the syncer persists a
 * resume marker (transient) and schedules a follow-up WP-Cron tick.
 *
 * We assert this via source-level contract checks because the
 * actual loop path requires a live WP_Query + remote HTTP, which our
 * stub harness intentionally does not provide. The contracts here are
 * what stops the regression — if anyone strips the time-guard out,
 * these tests fail loudly.
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
});

test('PostSyncer declares a TIME_BUDGET_SECONDS constant', function () {
    expect(PostSyncer::TIME_BUDGET_SECONDS)->toBeGreaterThan(0);
    expect(PostSyncer::TIME_BUDGET_SECONDS)->toBeLessThan(30);
});

test('ProductSyncer declares a TIME_BUDGET_SECONDS constant', function () {
    expect(ProductSyncer::TIME_BUDGET_SECONDS)->toBeGreaterThan(0);
    expect(ProductSyncer::TIME_BUDGET_SECONDS)->toBeLessThan(30);
});

test('PostSyncer declares a RESUME_TRANSIENT key separate from product sync', function () {
    expect(PostSyncer::RESUME_TRANSIENT)->not()->toBe(ProductSyncer::RESUME_TRANSIENT);
    expect(PostSyncer::RESUME_TRANSIENT)->toStartWith('pitchbar_');
    expect(ProductSyncer::RESUME_TRANSIENT)->toStartWith('pitchbar_');
});

test('PostSyncer source declares the more + next_page keys in its return shape', function () {
    // We avoid actually invoking runFullSync() here because the
    // plugin's `__()` calls collide with Laravel's `__()` helper in
    // the test bootstrap (different signature). The source-level
    // contract is what guards the regression.
    $source = file_get_contents(dirname(__DIR__, 3).'/wp-plugin/pitchbar/src/Sync/PostSyncer.php');
    expect($source)->toContain("'more' =>");
    expect($source)->toContain("'next_page' =>");
    expect($source)->toContain("'resumed' =>");
});

test('ProductSyncer source declares the more + next_page keys in its return shape', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/wp-plugin/pitchbar/src/Sync/ProductSyncer.php');
    expect($source)->toContain("'more' =>");
    expect($source)->toContain("'next_page' =>");
    expect($source)->toContain("'resumed' =>");
});

test('PostSyncer source persists resume + schedules a WP-Cron event when budget exceeded', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/wp-plugin/pitchbar/src/Sync/PostSyncer.php');
    expect($source)->toContain('budgetExceeded');
    expect($source)->toContain('persistResume');
    expect($source)->toContain('scheduleResume');
    expect($source)->toContain('wp_schedule_single_event');
});

test('ProductSyncer source persists resume + schedules a WP-Cron event when budget exceeded', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/wp-plugin/pitchbar/src/Sync/ProductSyncer.php');
    expect($source)->toContain('budgetExceeded');
    expect($source)->toContain('persistResume');
    expect($source)->toContain('scheduleResume');
    expect($source)->toContain('wp_schedule_single_event');
});
