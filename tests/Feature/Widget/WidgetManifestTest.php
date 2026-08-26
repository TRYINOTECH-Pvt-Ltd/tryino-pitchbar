<?php

use Illuminate\Support\Facades\File;

test('manifest endpoint returns json when file exists', function () {
    $path = public_path('widget/manifest.json');
    $existed = File::exists($path);
    $backup = $existed ? File::get($path) : null;

    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode([
        'version' => '9.9.9',
        'hash' => 'abc123abc123',
        'file' => 'widget.abc123abc123.js',
        'url' => '/widget/widget.abc123abc123.js',
        'generated_at' => '2026-05-16T00:00:00Z',
    ]));

    try {
        $response = $this->get('/widget/manifest.json')
            ->assertOk()
            ->assertJsonPath('hash', 'abc123abc123')
            ->assertJsonPath('file', 'widget.abc123abc123.js');

        $cc = $response->headers->get('Cache-Control');
        expect($cc)->toContain('max-age=60');
        expect($cc)->toContain('public');
        expect($cc)->toContain('must-revalidate');
    } finally {
        if ($existed && $backup !== null) {
            File::put($path, $backup);
        } else {
            File::delete($path);
        }
    }
});

test('manifest endpoint falls back when file missing', function () {
    $path = public_path('widget/manifest.json');
    $existed = File::exists($path);
    $backup = $existed ? File::get($path) : null;

    if ($existed) {
        File::delete($path);
    }

    try {
        $this->get('/widget/manifest.json')
            ->assertOk()
            ->assertJsonPath('version', 'dev')
            ->assertJsonPath('file', 'widget.js');
    } finally {
        if ($existed && $backup !== null) {
            File::put($path, $backup);
        }
    }
});
