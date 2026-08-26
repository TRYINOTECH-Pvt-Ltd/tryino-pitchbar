<?php

use App\Models\AppSetting;
use App\Models\ChangelogEntry;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['changelog.bootstrap_dir' => null]);
});

/**
 * Per-page SEO surface — every public route emits a description,
 * canonical URL, Open Graph block, Twitter card, and JSON-LD
 * organization. Home additionally emits SoftwareApplication and
 * FAQPage. The render is in `resources/views/app.blade.php`; the
 * payload is built in `App\Support\SeoMeta`.
 */
test('home emits per-route meta block + JSON-LD', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<meta name="description"')
        ->toContain('Turn every high-intent page')
        ->toContain('<link rel="canonical"')
        ->toContain('<meta property="og:type" content="website">')
        ->toContain('<meta property="og:site_name"')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->toContain('"@type":"Organization"')
        ->toContain('"@type":"SoftwareApplication"')
        ->toContain('"@type":"FAQPage"')
        ->toContain('"@type":"Question"');
});

test('marketing subpages each set their own title + description', function () {
    foreach (['/pricing', '/how-it-works', '/integrations', '/privacy', '/terms'] as $path) {
        $html = $this->get($path)->assertOk()->getContent();
        expect($html)
            ->toContain('<meta name="description"')
            ->toContain('<link rel="canonical"')
            ->and($html)->toContain($path);
    }
});

test('canonical url honours the configured app url', function () {
    $html = $this->get('/pricing')->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.config('app.url').'/pricing">');
});

test('changelog page surfaces the latest version in the description when published entries exist', function () {
    ChangelogEntry::create([
        'version' => 'v9.9.9',
        'released_at' => now(),
        'status' => ChangelogEntry::STATUS_PUBLISHED,
        'title' => 'Test release',
        'body' => '## Headline',
    ]);

    $html = $this->get('/changelog')->assertOk()->getContent();

    expect($html)
        ->toContain('v9.9.9')
        ->toContain('<meta name="description"');
});

test('renamed install changes brand in the title and description', function () {
    AppSetting::singleton()->forceFill([
        'site_title' => 'Acme Assist',
    ])->save();
    AppSetting::flushSingleton();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('Acme Assist')
        ->not->toContain('Pitchbar — AI sales assistant');
});
