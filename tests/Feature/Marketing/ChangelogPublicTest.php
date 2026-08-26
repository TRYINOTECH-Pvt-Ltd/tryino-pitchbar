<?php

use App\Models\ChangelogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['changelog.bootstrap_dir' => null]);
});

test('GET /changelog renders only published entries', function () {
    ChangelogEntry::create([
        'version' => 'v1.0.0',
        'title' => 'Public release',
        'body' => 'Body',
        'status' => 'published',
        'released_at' => '2026-05-01',
    ]);
    ChangelogEntry::create([
        'version' => 'v1.1.0',
        'title' => 'Draft release',
        'body' => 'Body',
        'status' => 'draft',
    ]);
    ChangelogEntry::create([
        'version' => 'v0.9.0',
        'title' => 'Archived release',
        'body' => 'Body',
        'status' => 'archived',
    ]);

    $response = $this->get('/changelog');
    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->component('marketing/changelog')
        ->has('entries', 1)
        ->where('entries.0.version', 'v1.0.0')
        ->where('entries.0.title', 'Public release'));
});

test('GET /changelog.json returns the JSON feed', function () {
    ChangelogEntry::create([
        'version' => 'v1.0.0',
        'title' => 'Public release',
        'body' => 'Body',
        'status' => 'published',
        'released_at' => '2026-05-01',
    ]);

    $response = $this->getJson('/changelog.json');
    $response->assertOk();
    expect($response->json('entries'))->toHaveCount(1);
    expect($response->json('entries.0.version'))->toBe('v1.0.0');
});

test('GET /changelog is publicly accessible without auth', function () {
    ChangelogEntry::create([
        'version' => 'v1.0.0',
        'title' => 'Public release',
        'body' => 'Body',
        'status' => 'published',
    ]);

    $response = $this->get('/changelog');
    $response->assertOk();
    $response->assertViewIs('app');
});

test('GET /changelog rebrands literal "Pitchbar" in title and body when site_title differs', function () {
    config(['branding.site_title' => 'RepliBar']);

    ChangelogEntry::create([
        'version' => 'v1.0.0',
        'title' => 'Pitchbar ships toMarkdown',
        'body' => 'Every Pitchbar deployment now bundles toMarkdown.',
        'status' => 'published',
        'released_at' => '2026-05-01',
    ]);

    $response = $this->get('/changelog');
    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->component('marketing/changelog')
        ->where('brand', 'RepliBar')
        ->where('entries.0.title', 'RepliBar ships toMarkdown')
        ->where('entries.0.body', 'Every RepliBar deployment now bundles toMarkdown.'));
});

test('GET /changelog.json rebrands "Pitchbar" tokens in body when site_title differs', function () {
    config(['branding.site_title' => 'RepliBar']);

    ChangelogEntry::create([
        'version' => 'v1.0.0',
        'title' => 'Public release',
        'body' => 'Pitchbar adds Stripe billing.',
        'status' => 'published',
        'released_at' => '2026-05-01',
    ]);

    $response = $this->getJson('/changelog.json');
    $response->assertOk();
    expect($response->json('brand'))->toBe('RepliBar');
    expect($response->json('entries.0.body'))->toBe('RepliBar adds Stripe billing.');
});

test('GET /changelog preserves "Pitchbar" verbatim when install still uses default brand', function () {
    config(['branding.site_title' => null]);
    config(['app.name' => 'Pitchbar']);

    ChangelogEntry::create([
        'version' => 'v1.0.0',
        'title' => 'Pitchbar ships',
        'body' => 'Pitchbar adds Stripe billing.',
        'status' => 'published',
        'released_at' => '2026-05-01',
    ]);

    $response = $this->get('/changelog');
    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('entries.0.title', 'Pitchbar ships')
        ->where('entries.0.body', 'Pitchbar adds Stripe billing.'));
});

test('POST /changelog/seen requires auth and stamps last_changelog_seen_at', function () {
    $this->post('/changelog/seen')->assertRedirect('/login');

    $user = User::factory()->create();
    expect($user->last_changelog_seen_at)->toBeNull();

    $this->actingAs($user)->postJson('/changelog/seen')->assertOk();

    expect($user->fresh()->last_changelog_seen_at)->not->toBeNull();
});
