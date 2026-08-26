<?php

use App\Models\ChangelogEntry;
use App\Services\Changelog\ChangelogStore;
use Illuminate\Support\Facades\Storage;

/**
 * Markdown-source bootstrap behaviour. The store seeds entries
 * from `database/changelog-entries/v*.md` files on every read
 * AND keeps them in sync with the markdown body — buyers can
 * pull a new code version and their /changelog updates without
 * any manual step. The exception: entries that an admin has
 * touched through the UI carry `source = 'admin'` and are never
 * overwritten by the bootstrap, so human edits stick forever.
 */
beforeEach(function () {
    Storage::fake('local');
});

function bootstrapDirWith(string $version, string $title, string $body): string
{
    $dir = sys_get_temp_dir().'/pitchbar-changelog-test-'.uniqid('', true);
    mkdir($dir, recursive: true);
    file_put_contents("{$dir}/{$version}.md", "---\nversion: {$version}\ntitle: {$title}\nreleased_at: 2026-05-01\n---\n\n{$body}\n");
    config(['changelog.bootstrap_dir' => $dir]);

    return $dir;
}

test('bootstrap creates entries from markdown files', function () {
    bootstrapDirWith('v1.0.0', 'Initial release', '## Headlines');

    $entries = ChangelogEntry::all();

    expect($entries)->toHaveCount(1);
    expect($entries->first()->version)->toBe('v1.0.0');
    expect($entries->first()->body)->toContain('## Headlines');
    expect($entries->first()->source)->toBe('markdown');
});

test('bootstrap updates an existing markdown-sourced entry when the markdown changes', function () {
    $dir = bootstrapDirWith('v1.0.0', 'Initial', 'old body');

    // First read seeds the JSON with the old body.
    $first = ChangelogEntry::all()->first();
    expect($first->body)->toContain('old body');

    // Edit the markdown file as a code-pull would.
    file_put_contents("{$dir}/v1.0.0.md", "---\nversion: v1.0.0\ntitle: Initial\nreleased_at: 2026-05-01\n---\n\nnew body content\n");

    // Re-read — bootstrap notices the body drift and updates the JSON.
    $second = ChangelogEntry::all()->first();
    expect($second->body)->toContain('new body content');
    expect($second->id)->toBe($first->id); // same row, just refreshed
});

test('bootstrap respects admin edits — never overwrites source=admin entries', function () {
    $dir = bootstrapDirWith('v1.0.0', 'Initial', 'shipped body');

    // Seed via bootstrap.
    $entry = ChangelogEntry::all()->first();

    // Admin opens the form and tweaks the body — the controller
    // stamps source=admin.
    $entry->update(['body' => 'admin tweaked this', 'source' => 'admin']);

    // Pull a new code version that updates the markdown.
    file_put_contents("{$dir}/v1.0.0.md", "---\nversion: v1.0.0\ntitle: Initial\nreleased_at: 2026-05-01\n---\n\nshipped body v2\n");

    // Bootstrap leaves the admin edit alone.
    $after = ChangelogEntry::all()->first();
    expect($after->body)->toBe('admin tweaked this');
    expect($after->source)->toBe('admin');
});

test('bootstrap is a no-op when nothing changed', function () {
    bootstrapDirWith('v1.0.0', 'Initial', 'shipped body');

    $store = app(ChangelogStore::class);
    $first = $store->all();
    $second = $store->all();

    // Same number of entries, same updated_at — no rewrites.
    expect($second)->toHaveCount(1);
    expect($second[0]['updated_at'])->toBe($first[0]['updated_at']);
});
