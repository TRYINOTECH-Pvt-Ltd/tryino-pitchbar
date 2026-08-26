<?php

use App\Services\Vertical\MetadataExtractor;
use App\Services\Vertical\SiteTypeDetector;

beforeEach(function () {
    $this->detector = new SiteTypeDetector(new MetadataExtractor);
});

function vfix(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/vertical/'.$name);
}

test('ecommerce fixture detects as ecommerce with confidence >= 0.5', function () {
    $result = $this->detector->detect(vfix('ecommerce.html'), 'https://shop.example.com/products/widget');

    expect($result['type'])->toBe('ecommerce');
    expect($result['confidence'])->toBeGreaterThanOrEqual(0.5);
});

test('docs fixture detects as documentation', function () {
    $result = $this->detector->detect(vfix('docs.html'), 'https://acme.dev/docs/quickstart');

    expect($result['type'])->toBe('documentation');
    expect($result['confidence'])->toBeGreaterThanOrEqual(0.5);
});

test('saas fixture detects as saas', function () {
    $result = $this->detector->detect(vfix('saas.html'), 'https://flowdeck.io');

    expect($result['type'])->toBe('saas');
    expect($result['confidence'])->toBeGreaterThan(0.0);
});

test('help_center fixture detects as help_center', function () {
    $result = $this->detector->detect(vfix('help_center.html'), 'https://acme.zendesk.com/help/articles/1');

    expect($result['type'])->toBe('help_center');
});

test('marketing fixture detects as marketing', function () {
    $result = $this->detector->detect(vfix('marketing.html'), 'https://acmestudio.com');

    expect($result['type'])->toBe('marketing');
});

test('internal_kb fixture detects as internal_kb', function () {
    $result = $this->detector->detect(vfix('internal_kb.html'), 'https://wiki.local/');

    expect($result['type'])->toBe('internal_kb');
});

test('ambiguous fixture falls through to generic', function () {
    $result = $this->detector->detect(vfix('ambiguous.html'), 'https://example.com');

    expect($result['type'])->toBe('generic');
    expect($result['confidence'])->toBe(0.0);
});

test('mixed signals: ecommerce wins, docs in alternatives', function () {
    $result = $this->detector->detect(vfix('mixed_signals.html'), 'https://acme.com/products/device');

    expect($result['type'])->toBe('ecommerce');
    $altTypes = array_map(fn ($a) => $a['type'], $result['alternatives']);
    expect($altTypes)->toContain('documentation');
});

test('every result has the expected shape', function () {
    $result = $this->detector->detect(vfix('ecommerce.html'), 'https://shop.example.com');

    expect($result)->toHaveKeys(['type', 'confidence', 'alternatives', 'signals']);
    expect($result['signals'])->toBeArray();
});
