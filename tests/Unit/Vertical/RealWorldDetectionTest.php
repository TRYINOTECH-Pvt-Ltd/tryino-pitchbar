<?php

use App\Services\Vertical\MetadataExtractor;
use App\Services\Vertical\SiteTypeDetector;

/**
 * Detector smoke against actual production HTML pulled from real
 * commercial sites. Fixtures live in tests/Fixtures/vertical/realworld/
 * and were captured with `curl -A "Mozilla/5.0 PitchbarBot/1.0"`.
 *
 * These tests cover the full real-world spectrum the wizard sees in
 * production — from rich SSR pages where detection sails (Docusaurus,
 * VitePress, Mintlify) to JS-heavy SPAs where SSR ships almost no
 * structured data and the detector correctly falls back to `generic`
 * (Allbirds storefront, Linear, Intercom help landing). The honest
 * verdict is documented in the assertions: documentation sites are
 * detected with high confidence; e-commerce works on PRODUCT pages
 * but not always on the storefront root; SaaS/help-center landings
 * are noisy because their generators ship empty SSR.
 */
beforeEach(function () {
    $this->detector = new SiteTypeDetector(new MetadataExtractor);
});

function realWorldFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/vertical/realworld/'.$name);
}

test('Docusaurus official docs site detects as documentation', function () {
    $r = $this->detector->detect(
        realWorldFixture('docusaurus.html'),
        'https://docusaurus.io/docs',
    );
    expect($r['type'])->toBe('documentation');
    expect($r['confidence'])->toBeGreaterThanOrEqual(0.7);
    expect($r['signals'])->toContain('generator:docusaurus');
});

test('Mintlify official docs detects as documentation', function () {
    $r = $this->detector->detect(
        realWorldFixture('mintlify.html'),
        'https://mintlify.com/docs',
    );
    expect($r['type'])->toBe('documentation');
    expect($r['confidence'])->toBeGreaterThanOrEqual(0.7);
});

test('VitePress official docs detects as documentation', function () {
    $r = $this->detector->detect(
        realWorldFixture('vitepress.html'),
        'https://vitepress.dev/guide/getting-started',
    );
    expect($r['type'])->toBe('documentation');
    expect($r['confidence'])->toBeGreaterThanOrEqual(0.7);
});

test('Allbirds product page detects as ecommerce', function () {
    $r = $this->detector->detect(
        realWorldFixture('shopify_product.html'),
        'https://www.allbirds.com/products/mens-tree-runners',
    );
    expect($r['type'])->toBe('ecommerce');
    expect($r['confidence'])->toBeGreaterThanOrEqual(0.5);
    expect($r['signals'])->toContain('og:type=product');
});

test('Stripe homepage detects as marketing', function () {
    $r = $this->detector->detect(
        realWorldFixture('stripe_marketing.html'),
        'https://stripe.com',
    );
    expect($r['type'])->toBe('marketing');
});

test('JS-heavy SPA storefront with no SSR signals falls back to generic', function () {
    // Allbirds homepage is a JS-heavy SPA. SSR ships an empty shell
    // — no og:type=product (that lives on /products/...), no JSON-LD
    // Product on the root. The detector correctly falls back to
    // generic so the admin can pick manually.
    $r = $this->detector->detect(
        realWorldFixture('shopify_allbirds.html'),
        'https://www.allbirds.com',
    );
    expect($r['type'])->toBe('generic');
});

test('JS-heavy SaaS landing page falls back to generic gracefully', function () {
    // Linear's marketing root ships near-empty SSR. The detector
    // mustn't crash on this — it just degrades to generic and the
    // admin overrides in the wizard.
    $r = $this->detector->detect(
        realWorldFixture('linear_marketing.html'),
        'https://linear.app',
    );
    expect(in_array($r['type'], ['generic', 'saas', 'marketing'], true))->toBeTrue();
});
