<?php

use App\Support\MarketingHomeContent;
use App\Support\MarketingShellContent;

/**
 * Marketing nav fixes:
 *   - the first nav item is "Home" (it points at /, so "Product" was a
 *     misleading label — buyer report),
 *   - an external docs URL repoints every built-in /documentation link
 *     (header + footer) at the operator's own docs site,
 *   - the shell content (pricing / how-it-works / etc) carries the
 *     show_documentation flag so the Documentation link is consistent
 *     across pages, not home-only.
 */
test('the first nav item is Home pointing at /, not Product', function () {
    $content = MarketingHomeContent::resolve([]);

    expect($content['nav_items'][0])->toBe(['label' => 'Home', 'href' => '/']);
});

test('a blank external docs URL leaves the built-in /documentation links untouched', function () {
    $content = MarketingHomeContent::resolve([]);

    expect($content['header']['resources_href'])->toBe('/documentation');
    $resources = collect($content['footer']['groups'])->firstWhere('title', 'Resources');
    expect($resources['links'][0]['href'])->toBe('/documentation');
});

test('resolve() never bakes the rewrite into storage — clearing the URL restores defaults', function () {
    // SystemController persists resolve() output; the rewrite must NOT
    // run there, or clearing docs_external_url could never restore the
    // built-in /documentation links.
    $stored = MarketingHomeContent::resolve([
        'header' => ['docs_external_url' => 'https://blengidocs.com'],
    ]);

    expect($stored['header']['resources_href'])->toBe('/documentation');
    $resources = collect($stored['footer']['groups'])->firstWhere('title', 'Resources');
    expect($resources['links'][0]['href'])->toBe('/documentation');
});

test('an external docs URL repoints header + footer doc links, preserving the sub-path', function () {
    $content = MarketingHomeContent::applyExternalDocsUrl(
        MarketingHomeContent::resolve([
            'header' => ['docs_external_url' => 'https://blengidocs.com/'],
        ]),
    );

    // Header Documentation link → docs home.
    expect($content['header']['resources_href'])->toBe('https://blengidocs.com');

    // Footer Resources column: /documentation → base, /documentation/architecture → base/architecture.
    $resources = collect($content['footer']['groups'])->firstWhere('title', 'Resources');
    $hrefs = collect($resources['links'])->pluck('href')->all();
    expect($hrefs)->toContain('https://blengidocs.com')
        ->and($hrefs)->toContain('https://blengidocs.com/architecture');

    // Footer Build column: /documentation/quickstart → base/quickstart.
    $build = collect($content['footer']['groups'])->firstWhere('title', 'Build');
    expect(collect($build['links'])->pluck('href'))->toContain('https://blengidocs.com/quickstart');

    // Non-doc links are left alone.
    $product = collect($content['footer']['groups'])->firstWhere('title', 'Product');
    expect(collect($product['links'])->pluck('href'))->toContain('/pricing');
});

test('the shell content carries show_documentation so non-home pages match the home header', function () {
    $home = MarketingHomeContent::resolve([
        'header' => ['show_documentation' => true],
    ]);
    $shell = MarketingShellContent::resolve($home);

    expect($shell['header']['show_documentation'])->toBeTrue();
});

test('the external docs URL also flows into the shell content used by pricing/etc', function () {
    $home = MarketingHomeContent::resolve([
        'header' => ['docs_external_url' => 'https://blengidocs.com'],
    ]);
    $shell = MarketingShellContent::resolve($home);

    expect($shell['header']['resources_href'])->toBe('https://blengidocs.com');
    $resources = collect($shell['footer']['groups'])->firstWhere('title', 'Resources');
    expect(collect($resources['links'])->pluck('href'))->toContain('https://blengidocs.com/architecture');
});
