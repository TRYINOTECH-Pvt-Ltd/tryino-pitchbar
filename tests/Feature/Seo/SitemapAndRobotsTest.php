<?php

use App\Models\ChangelogEntry;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['changelog.bootstrap_dir' => null]);
});

test('robots.txt allows public surfaces and disallows admin / app / api', function () {
    $response = $this->get('/robots.txt')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/plain');

    $body = $response->getContent();
    expect($body)
        ->toContain('User-agent: *')
        ->toContain('Allow: /')
        ->toContain('Disallow: /admin')
        ->toContain('Disallow: /app')
        ->toContain('Disallow: /api/')
        ->toContain('Disallow: /settings')
        ->toContain('Disallow: /login')
        ->toContain('Sitemap: '.config('app.url').'/sitemap.xml');
});

test('sitemap.xml lists every marketing route + every documentation page + published changelog entries', function () {
    ChangelogEntry::create([
        'version' => 'v1.2.3',
        'released_at' => now(),
        'status' => ChangelogEntry::STATUS_PUBLISHED,
        'title' => 'Sample',
        'body' => 'Body',
    ]);

    cache()->forget('seo:sitemap.xml');

    $response = $this->get('/sitemap.xml')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    $xml = $response->getContent();

    expect($xml)
        ->toContain('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('<urlset')
        // Marketing routes
        ->toContain('<loc>'.config('app.url').'/</loc>')
        ->toContain('<loc>'.config('app.url').'/pricing</loc>')
        ->toContain('<loc>'.config('app.url').'/how-it-works</loc>')
        ->toContain('<loc>'.config('app.url').'/integrations</loc>')
        ->toContain('<loc>'.config('app.url').'/changelog</loc>')
        ->toContain('<loc>'.config('app.url').'/privacy</loc>')
        ->toContain('<loc>'.config('app.url').'/terms</loc>')
        ->toContain('<loc>'.config('app.url').'/documentation</loc>')
        // Documentation slugs
        ->toContain('/documentation/quickstart')
        ->toContain('/documentation/embed')
        ->toContain('/documentation/workflows')
        // Published changelog entries
        ->toContain('/changelog#v1.2.3');
});

test('sitemap caches per request', function () {
    cache()->forget('seo:sitemap.xml');

    $first = $this->get('/sitemap.xml')->assertOk()->getContent();
    $second = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($first)->toBe($second);
    expect(cache()->has('seo:sitemap.xml'))->toBeTrue();
});

test('sitemap excludes draft / archived changelog entries', function () {
    ChangelogEntry::create([
        'version' => 'v0.1.0-draft',
        'released_at' => now(),
        'status' => ChangelogEntry::STATUS_DRAFT,
        'title' => 'Hidden',
        'body' => 'Body',
    ]);

    cache()->forget('seo:sitemap.xml');

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->not->toContain('v0.1.0-draft');
});
