<?php

use App\Services\Crawl\SitemapDiscoverer;
use App\Support\UrlSafetyGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

test('sitemap discovery rejects child URLs that resolve to loopback', function () {
    $mock = new MockHandler([
        new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap><loc>http://127.0.0.1:6379/internal</loc></sitemap>
    <sitemap><loc>http://169.254.169.254/latest/meta-data/</loc></sitemap>
</sitemapindex>
XML),
    ]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $discoverer = new SitemapDiscoverer($client, new UrlSafetyGuard);

    $urls = $discoverer->discover('https://example.com');

    expect($urls)->toBe([]);
});

test('sitemap discovery still expands safe child sitemaps', function () {
    $mock = new MockHandler([
        new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap><loc>https://example.com/sitemap-posts.xml</loc></sitemap>
</sitemapindex>
XML),
        new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>https://example.com/blog/post-1</loc></url>
    <url><loc>https://example.com/blog/post-2</loc></url>
</urlset>
XML),
    ]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $discoverer = new SitemapDiscoverer($client, new UrlSafetyGuard);

    $urls = $discoverer->discover('https://example.com');

    expect($urls)->toContain('https://example.com/blog/post-1');
    expect($urls)->toContain('https://example.com/blog/post-2');
});

test('aggregate byte budget caps runaway sitemapindex', function () {
    $big = str_repeat('<sitemap><loc>https://example.com/sitemap.xml</loc></sitemap>', 100000);
    $payload = '<?xml version="1.0"?><sitemapindex>'.$big.'</sitemapindex>';

    $mock = new MockHandler([
        new Response(200, [], $payload),
    ]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $discoverer = new SitemapDiscoverer($client, new UrlSafetyGuard);

    $start = memory_get_usage();
    $discoverer->discover('https://example.com');
    $delta = memory_get_usage() - $start;

    expect($delta)->toBeLessThan(64 * 1024 * 1024);
});
