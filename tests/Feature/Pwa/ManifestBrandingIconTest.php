<?php

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('manifest prepends operator-uploaded favicon to icons[]', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->image('brand.png', 64, 64);
    $path = $file->store('branding', 'public');

    AppSetting::singleton()->forceFill(['favicon_path' => $path])->save();
    AppSetting::flushSingleton();

    $icons = $this->get('/manifest.webmanifest')->json('icons');

    expect($icons)->toHaveCount(3);
    expect($icons[0]['sizes'])->toBe('any');
    expect($icons[0]['type'])->toBe('image/png');
    // User-uploaded favicons aren't safe-zone designed — must NOT be
    // tagged maskable or Android crops the corners off.
    expect($icons[0]['purpose'])->toBe('any');
    expect($icons[0]['src'])->toContain('brand');

    // Fallbacks remain so installers demanding exact PNG sizes match.
    expect($icons[1]['sizes'])->toBe('192x192');
    expect($icons[2]['sizes'])->toBe('512x512');
});

test('manifest icons fall back to bundled placeholders when no favicon set', function () {
    AppSetting::singleton()->forceFill(['favicon_path' => null])->save();
    AppSetting::flushSingleton();

    $icons = $this->get('/manifest.webmanifest')->json('icons');

    expect($icons)->toHaveCount(2);
    expect($icons[0]['src'])->toBe('/icons/icon-192.png');
    expect($icons[1]['src'])->toBe('/icons/icon-512.png');
});

test('manifest favicon icon type is inferred from extension', function () {
    Storage::fake('public');

    $cases = [
        ['file' => 'brand.svg', 'expected' => 'image/svg+xml'],
        ['file' => 'brand.ico', 'expected' => 'image/x-icon'],
        ['file' => 'brand.webp', 'expected' => 'image/webp'],
        ['file' => 'brand.jpg', 'expected' => 'image/jpeg'],
    ];

    foreach ($cases as $case) {
        AppSetting::singleton()->forceFill([
            'favicon_path' => 'branding/'.$case['file'],
        ])->save();
        AppSetting::flushSingleton();

        $icons = $this->get('/manifest.webmanifest')->json('icons');
        expect($icons[0]['type'])->toBe($case['expected']);
    }
});

test('GET /dashboard (the new start_url) resolves for unauthenticated installers', function () {
    // PWA install opens start_url before the user logs in. Fortify
    // should redirect to /login rather than 404 — that's what makes
    // /dashboard a safe start_url for the manifest.
    $response = $this->get('/dashboard');

    expect($response->status())->toBeIn([200, 302]);
    if ($response->status() === 302) {
        expect($response->headers->get('Location'))->toContain('login');
    }
});

test('service worker VERSION is bumped past v1 (purges stale 404 shell cache)', function () {
    // After fixing start_url, existing PWA installs still have the
    // bad shell cached. Bumping VERSION triggers the activate-time
    // cleanup loop in sw.js so installs heal on next visit.
    $sw = (string) file_get_contents(public_path('sw.js'));
    expect($sw)->toMatch('/const VERSION = \'pitchbar-shell-v[2-9]\d*\'/');
});
