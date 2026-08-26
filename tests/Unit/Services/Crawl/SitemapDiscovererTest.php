<?php

use App\Services\Crawl\SitemapDiscoverer;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Buyer report: added a sitemap with 100+ URLs, only the homepage got
 * indexed. Two compounding bugs:
 *
 *   1. discover() always appended /sitemap.xml to the input URL — so a
 *      user pasting the literal sitemap URL got 404'd
 *      (example.com/sitemap.xml/sitemap.xml).
 *   2. <sitemapindex> XML was treated as if it were a flat <urlset>,
 *      so the discoverer returned a list of CHILD SITEMAP URLs as
 *      "pages" and each one failed the <200 char content gate.
 *
 * Tests pin both fixes plus the recursion + dedupe + cap behaviour.
 */
function discovererWithResponses(array $responses): SitemapDiscoverer
{
    $mock = new MockHandler($responses);
    $http = new Guzzle(['handler' => HandlerStack::create($mock)]);

    return new SitemapDiscoverer($http);
}

test('returns [] on empty input', function () {
    $d = discovererWithResponses([]);
    expect($d->discover(''))->toBe([]);
    expect($d->discover('   '))->toBe([]);
});

test('domain-root URL probes /sitemap.xml + /sitemap_index.xml', function () {
    // First candidate (/sitemap.xml) returns a valid urlset.
    $d = discovererWithResponses([
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <urlset>
            <url><loc>https://example.com/a</loc></url>
            <url><loc>https://example.com/b</loc></url>
        </urlset>
        XML),
    ]);

    expect($d->discover('https://example.com'))
        ->toBe(['https://example.com/a', 'https://example.com/b']);
});

test('direct sitemap URL is fetched verbatim (no /sitemap.xml append)', function () {
    // Buyer's actual repro: pasting the literal /sitemap.xml URL.
    // Pre-fix the discoverer appended a second /sitemap.xml and 404'd.
    $d = discovererWithResponses([
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <urlset>
            <url><loc>https://shop.example.com/red-pen</loc></url>
            <url><loc>https://shop.example.com/blue-pen</loc></url>
        </urlset>
        XML),
    ]);

    $urls = $d->discover('https://shop.example.com/sitemap.xml');
    expect($urls)->toBe([
        'https://shop.example.com/red-pen',
        'https://shop.example.com/blue-pen',
    ]);
});

test('non-root .xml path is also treated as a direct sitemap', function () {
    $d = discovererWithResponses([
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <urlset>
            <url><loc>https://docs.example.com/intro</loc></url>
        </urlset>
        XML),
    ]);

    expect($d->discover('https://docs.example.com/docs/sitemap.xml'))
        ->toBe(['https://docs.example.com/intro']);
});

test('sitemap-index recurses into child sitemaps and aggregates URLs', function () {
    // First response: a sitemapindex pointing at 2 child sitemaps.
    // Then each child returns its own urlset.
    $d = discovererWithResponses([
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <sitemapindex>
            <sitemap><loc>https://example.com/sitemap-posts.xml</loc></sitemap>
            <sitemap><loc>https://example.com/sitemap-pages.xml</loc></sitemap>
        </sitemapindex>
        XML),
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <urlset>
            <url><loc>https://example.com/posts/one</loc></url>
            <url><loc>https://example.com/posts/two</loc></url>
        </urlset>
        XML),
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <urlset>
            <url><loc>https://example.com/about</loc></url>
            <url><loc>https://example.com/contact</loc></url>
        </urlset>
        XML),
    ]);

    expect($d->discover('https://example.com'))->toBe([
        'https://example.com/posts/one',
        'https://example.com/posts/two',
        'https://example.com/about',
        'https://example.com/contact',
    ]);
});

test('cap is enforced after recursion', function () {
    // 3-URL child sitemap, max=2 — should truncate, NOT crawl all 3.
    $d = discovererWithResponses([
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <sitemapindex>
            <sitemap><loc>https://example.com/sitemap-1.xml</loc></sitemap>
        </sitemapindex>
        XML),
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <urlset>
            <url><loc>https://example.com/a</loc></url>
            <url><loc>https://example.com/b</loc></url>
            <url><loc>https://example.com/c</loc></url>
        </urlset>
        XML),
    ]);

    expect($d->discover('https://example.com', max: 2))->toBe([
        'https://example.com/a',
        'https://example.com/b',
    ]);
});

test('duplicate URLs across child sitemaps are deduped', function () {
    $d = discovererWithResponses([
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <sitemapindex>
            <sitemap><loc>https://example.com/sitemap-1.xml</loc></sitemap>
            <sitemap><loc>https://example.com/sitemap-2.xml</loc></sitemap>
        </sitemapindex>
        XML),
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <urlset><url><loc>https://example.com/shared</loc></url></urlset>
        XML),
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <urlset><url><loc>https://example.com/shared</loc></url></urlset>
        XML),
    ]);

    expect($d->discover('https://example.com'))
        ->toBe(['https://example.com/shared']);
});

test('network failure on the first candidate falls through to the next', function () {
    // Domain-root input → 2 candidates (/sitemap.xml + /sitemap_index.xml).
    // First throws, second succeeds — the discoverer must keep going.
    $d = discovererWithResponses([
        new ConnectException(
            'connect failed',
            new Request('GET', 'https://example.com/sitemap.xml'),
        ),
        new Response(200, [], <<<'XML'
        <?xml version="1.0"?>
        <urlset><url><loc>https://example.com/from-index</loc></url></urlset>
        XML),
    ]);

    expect($d->discover('https://example.com'))
        ->toBe(['https://example.com/from-index']);
});

test('non-XML response is rejected (no <urlset> or <sitemapindex>)', function () {
    $d = discovererWithResponses([
        new Response(200, [], '<html><body>Not a sitemap</body></html>'),
        // Fallback candidate also misses.
        new Response(404, [], 'Not Found'),
    ]);

    expect($d->discover('https://example.com'))->toBe([]);
});
