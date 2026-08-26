<?php

use App\Enums\PlatformRole;
use App\Models\ChangelogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['changelog.bootstrap_dir' => null]);
});

function superAdminForChangelog(): User
{
    return User::factory()->create(['role' => PlatformRole::SuperAdmin]);
}

function makeChangelogEntry(array $overrides = []): ChangelogEntry
{
    return ChangelogEntry::create(array_merge([
        'version' => 'v1.0.0',
        'title' => 'Sample',
        'body' => '## Added',
        'status' => ChangelogEntry::STATUS_DRAFT,
        'released_at' => null,
    ], $overrides));
}

test('GET /admin/changelog renders for super_admin', function () {
    $admin = superAdminForChangelog();
    makeChangelogEntry([
        'version' => 'v1.0.0',
        'status' => ChangelogEntry::STATUS_PUBLISHED,
        'released_at' => '2026-05-01',
    ]);

    $this->actingAs($admin)->get('/admin/changelog')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('admin/changelog/index')
            ->has('entries', 1)
            ->where('entries.0.version', 'v1.0.0'));
});

test('a customer cannot reach /admin/changelog (404 hides existence)', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);
    $this->actingAs($customer)->get('/admin/changelog')->assertNotFound();
});

test('POST creates a draft entry', function () {
    $admin = superAdminForChangelog();

    $this->actingAs($admin)->post('/admin/changelog', [
        'version' => 'v1.4.0',
        'released_at' => '2026-05-09',
        'status' => 'draft',
        'title' => 'Workflow visual editor',
        'body' => "## Added\n- Canvas page",
    ])->assertRedirect();

    $entry = ChangelogEntry::findByVersion('v1.4.0');
    expect($entry)->not->toBeNull();
    expect($entry->status)->toBe('draft');
    expect($entry->title)->toBe('Workflow visual editor');
});

test('POST rejects a duplicate version', function () {
    $admin = superAdminForChangelog();
    makeChangelogEntry(['version' => 'v1.0.0']);

    $this->actingAs($admin)->post('/admin/changelog', [
        'version' => 'v1.0.0',
        'title' => 'Dup',
        'body' => 'whatever',
    ])->assertSessionHasErrors('version');
});

test('publish action flips status + stamps released_at', function () {
    $admin = superAdminForChangelog();
    $entry = makeChangelogEntry([
        'version' => 'v1.4.0',
        'status' => 'draft',
        'released_at' => null,
    ]);

    $this->actingAs($admin)
        ->post("/admin/changelog/{$entry->id}/publish")
        ->assertRedirect();

    $fresh = ChangelogEntry::findById($entry->id);
    expect($fresh->status)->toBe('published');
    expect($fresh->released_at)->not->toBeNull();
});

test('a published entry refuses to change its version', function () {
    $admin = superAdminForChangelog();
    $entry = makeChangelogEntry([
        'version' => 'v1.4.0',
        'status' => 'published',
        'released_at' => '2026-05-01',
    ]);

    $this->actingAs($admin)->patch("/admin/changelog/{$entry->id}", [
        'version' => 'v1.4.1',
        'title' => $entry->title,
        'body' => $entry->body,
        'status' => 'published',
    ])->assertSessionHasErrors('version');

    expect(ChangelogEntry::findById($entry->id)->version)->toBe('v1.4.0');
});

test('archive flips status to archived but keeps the row', function () {
    $admin = superAdminForChangelog();
    $entry = makeChangelogEntry([
        'version' => 'v2.0.0',
        'status' => 'published',
    ]);

    $this->actingAs($admin)
        ->delete("/admin/changelog/{$entry->id}")
        ->assertRedirect();

    expect(ChangelogEntry::findById($entry->id)->status)->toBe('archived');
});
