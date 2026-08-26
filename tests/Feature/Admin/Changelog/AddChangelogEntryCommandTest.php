<?php

use App\Models\ChangelogEntry;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['changelog.bootstrap_dir' => null]);
});

test('changelog:add creates a draft entry', function () {
    $this->artisan('changelog:add', [
        'version' => 'v1.4.0',
        '--title' => 'Workflow visual editor',
        '--body' => "## Added\n- Canvas page",
    ])->assertExitCode(0);

    $entry = ChangelogEntry::findByVersion('v1.4.0');
    expect($entry)->not->toBeNull();
    expect($entry->status)->toBe('draft');
    expect($entry->title)->toBe('Workflow visual editor');
});

test('changelog:add with --status=published stamps released_at to today by default', function () {
    $this->artisan('changelog:add', [
        'version' => 'v1.5.0',
        '--title' => 'New release',
        '--body' => "## Added\n- branch step",
        '--status' => 'published',
    ])->assertExitCode(0);

    $entry = ChangelogEntry::findByVersion('v1.5.0');
    expect($entry)->not->toBeNull();
    expect($entry->status)->toBe('published');
    expect($entry->released_at)->not->toBeNull();
});

test('changelog:add is idempotent on existing version', function () {
    ChangelogEntry::create([
        'version' => 'v1.0.0',
        'title' => 'Original',
        'body' => 'body',
        'status' => 'draft',
    ]);

    $this->artisan('changelog:add', [
        'version' => 'v1.0.0',
        '--title' => 'Different title',
        '--body' => 'Different body',
    ])->expectsOutputToContain('already exists')->assertExitCode(0);

    $entry = ChangelogEntry::findByVersion('v1.0.0');
    expect($entry->title)->toBe('Original');
});

test('changelog:add rejects unknown status', function () {
    $this->artisan('changelog:add', [
        'version' => 'v1.0.0',
        '--title' => 't',
        '--body' => 'b',
        '--status' => 'launched',
    ])->assertExitCode(1);
});

test('changelog:add rejects empty title or body', function () {
    $this->artisan('changelog:add', [
        'version' => 'v1.0.0',
        '--title' => '',
        '--body' => 'b',
    ])->assertExitCode(1);
});
