<?php

use App\Services\Crawl\SiteDiscoverer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

function discoverer(MockHandler $mock): SiteDiscoverer
{
    return new SiteDiscoverer(new Client(['handler' => HandlerStack::create($mock)]));
}

test('returns empty arrays for an unparseable URL', function () {
    $d = discoverer(new MockHandler([]));
    $out = $d->discover('not a url');

    expect($out['sitemap_urls'])->toBe([]);
    expect($out['probed_urls'])->toBe([]);
});

test('reads Sitemap: directives from robots.txt', function () {
    $robots = "User-agent: *\nDisallow: /admin\nSitemap: https://example.com/my-sitemap.xml";
    $sitemap = '<?xml version="1.0"?><urlset><url><loc>https://example.com/a</loc></url><url><loc>https://example.com/b</loc></url></urlset>';

    // Sequence: robots.txt → its sitemap → 9 HEAD probes (all 404 → empty)
    $responses = [
        new Response(200, [], $robots),
        new Response(200, [], $sitemap),
    ];
    for ($i = 0; $i < 9; $i++) {
        $responses[] = new Response(404);
    }

    $out = discoverer(new MockHandler($responses))->discover('https://example.com');

    expect($out['sitemap_urls'])->toBe(['https://example.com/a', 'https://example.com/b']);
});

test('falls back to /sitemap.xml when robots.txt has no Sitemap directive', function () {
    $robots = "User-agent: *\nDisallow: /admin";
    $sitemap = '<urlset><url><loc>https://example.com/page</loc></url></urlset>';

    $responses = [
        new Response(200, [], $robots),
        new Response(200, [], $sitemap),
    ];
    for ($i = 0; $i < 9; $i++) {
        $responses[] = new Response(404);
    }

    $out = discoverer(new MockHandler($responses))->discover('https://example.com');

    expect($out['sitemap_urls'])->toBe(['https://example.com/page']);
});

test('recurses into <sitemapindex> one level deep', function () {
    $robots = '';
    $index = '<sitemapindex><sitemap><loc>https://example.com/sitemap-1.xml</loc></sitemap></sitemapindex>';
    $child = '<urlset><url><loc>https://example.com/x</loc></url><url><loc>https://example.com/y</loc></url></urlset>';

    $responses = [
        new Response(200, [], $robots),
        new Response(200, [], $index),
        new Response(404), // sitemap_index.xml fallback (won't be tried because /sitemap.xml succeeded)
        new Response(200, [], $child),
    ];
    for ($i = 0; $i < 9; $i++) {
        $responses[] = new Response(404);
    }

    $out = discoverer(new MockHandler($responses))->discover('https://example.com');

    expect($out['sitemap_urls'])->toContain('https://example.com/x');
    expect($out['sitemap_urls'])->toContain('https://example.com/y');
});

test('probes common paths when no sitemap exists', function () {
    $robots = '';
    $no404 = static fn () => new Response(200);

    $responses = [
        new Response(200, [], $robots),
        new Response(404), // /sitemap.xml
        new Response(404), // /sitemap_index.xml
        $no404(), // /about
        $no404(), // /pricing
        new Response(404), // /features
        $no404(), // /products
        new Response(404), // /faq
        $no404(), // /docs
        new Response(404), // /help
        new Response(404), // /support
        new Response(404), // /contact
    ];

    $out = discoverer(new MockHandler($responses))->discover('https://example.com');

    expect($out['sitemap_urls'])->toBe([]);
    expect($out['probed_urls'])->toContain('https://example.com/about');
    expect($out['probed_urls'])->toContain('https://example.com/pricing');
    expect($out['probed_urls'])->toContain('https://example.com/products');
    expect($out['probed_urls'])->toContain('https://example.com/docs');
    expect($out['probed_urls'])->not->toContain('https://example.com/faq');
});

test('respects --max cap on sitemap URLs', function () {
    $robots = '';
    $sitemap = '<urlset>'.str_repeat('<url><loc>https://example.com/x</loc></url>', 100).'</urlset>';

    // Sitemap returns 100 urls (all the same after dedupe → 1). Probe still runs.
    $responses = [
        new Response(200, [], $robots),
        new Response(200, [], $sitemap),
    ];
    for ($i = 0; $i < 9; $i++) {
        $responses[] = new Response(404);
    }

    $out = discoverer(new MockHandler($responses))->discover('https://example.com', 5);

    expect(count($out['sitemap_urls']))->toBeLessThanOrEqual(5);
});

test('survives 5xx on robots.txt by falling through to defaults', function () {
    $sitemap = '<urlset><url><loc>https://example.com/a</loc></url></urlset>';

    $responses = [
        new Response(503), // robots.txt fail
        new Response(200, [], $sitemap),
    ];
    for ($i = 0; $i < 9; $i++) {
        $responses[] = new Response(404);
    }

    $out = discoverer(new MockHandler($responses))->discover('https://example.com');

    expect($out['sitemap_urls'])->toBe(['https://example.com/a']);
});
