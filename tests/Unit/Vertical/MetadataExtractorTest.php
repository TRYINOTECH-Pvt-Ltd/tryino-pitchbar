<?php

use App\Services\Vertical\MetadataExtractor;

beforeEach(function () {
    $this->extractor = new MetadataExtractor;
});

test('extracts og:type and og:* fields', function () {
    $html = '<html><head><meta property="og:type" content="product"><meta property="og:site_name" content="Acme"></head></html>';
    $result = $this->extractor->extract($html, 'https://acme.test/p/1');

    expect($result['og']['type'])->toBe('product');
    expect($result['og']['site_name'])->toBe('Acme');
});

test('extracts JSON-LD @type values', function () {
    $html = '<html><head><script type="application/ld+json">{"@type":"Product","name":"Widget"}</script></head></html>';
    $result = $this->extractor->extract($html, 'https://acme.test');

    expect($result['json_ld_types'])->toContain('Product');
});

test('flattens JSON-LD @graph', function () {
    $html = '<html><head><script type="application/ld+json">{"@graph":[{"@type":"WebSite"},{"@type":"BreadcrumbList"}]}</script></head></html>';
    $result = $this->extractor->extract($html, 'https://acme.test');

    expect($result['json_ld_types'])->toContain('WebSite');
    expect($result['json_ld_types'])->toContain('BreadcrumbList');
});

test('extracts generator meta', function () {
    $html = '<html><head><meta name="generator" content="Docusaurus v3.0.0"></head></html>';
    $result = $this->extractor->extract($html, 'https://acme.test');

    expect($result['generator'])->toBe('Docusaurus v3.0.0');
});

test('counts code blocks (>=3 flips has_code_blocks true)', function () {
    $html = '<html><body><pre><code>a</code></pre><pre><code>b</code></pre><pre><code>c</code></pre></body></html>';
    $result = $this->extractor->extract($html, 'https://acme.test');

    expect($result['has_code_blocks'])->toBeTrue();
});

test('two code blocks does not flip has_code_blocks', function () {
    $html = '<html><body><pre><code>a</code></pre><pre><code>b</code></pre></body></html>';
    $result = $this->extractor->extract($html, 'https://acme.test');

    expect($result['has_code_blocks'])->toBeFalse();
});

test('tolerates malformed HTML without throwing', function () {
    $result = $this->extractor->extract('<html><body><p>oops', 'https://acme.test');

    expect($result['json_ld_types'])->toBe([]);
    expect($result['og'])->toBe([]);
});

test('empty HTML returns an empty struct with url_path/host populated', function () {
    $result = $this->extractor->extract('', 'https://acme.test/foo');

    expect($result['og'])->toBe([]);
    expect($result['json_ld_types'])->toBe([]);
    expect($result['has_code_blocks'])->toBeFalse();
    expect($result['url_path'])->toBe('/foo');
    expect($result['url_host'])->toBe('acme.test');
});

test('extracts nav link text from header/nav anchors', function () {
    $html = '<html><body><header><nav><a href="/x">Pricing</a><a href="/y">Sign up</a></nav></header></body></html>';
    $result = $this->extractor->extract($html, 'https://acme.test');

    expect($result['nav_links'])->toContain('Pricing');
    expect($result['nav_links'])->toContain('Sign up');
});

test('detects <article> tag', function () {
    $html = '<html><body><article><h1>Doc</h1></article></body></html>';
    $result = $this->extractor->extract($html, 'https://acme.test');

    expect($result['has_article_tag'])->toBeTrue();
});
