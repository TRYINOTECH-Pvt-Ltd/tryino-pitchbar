<?php

use App\Http\Controllers\Admin\UploadController;
use App\Jobs\Crawl\CrawlPageJob;
use App\Jobs\Crawl\CrawlSourceJob;
use App\Jobs\Crawl\IndexDocumentJob;
use App\Jobs\Crawl\IndexTextSourceJob;
use App\Jobs\Crawl\IngestGoogleDocJob;
use App\Jobs\Crawl\IngestNotionPageJob;
use App\Jobs\Crawl\SyncGoogleSheetJob;
use App\Jobs\Crawl\SyncSqlSourceJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/**
 * Regression: the Sources "Reindex" button (SourceController::reindex)
 * used to dispatch CrawlSourceJob for EVERY source type. OAuth sources
 * (Google Docs/Sheets, Notion) and SQL sources carry no config.url, so
 * the web crawler failed with "No URL configured" and flipped a healthy
 * source to `failed`. Reported on a live install after a working Google
 * Doc broke on Reindex. These tests pin the type→job routing.
 */
function reindexTypeActor(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['user' => $user, 'agent' => $agent];
}

function makeReindexSource(string $agentId, string $type, array $config): Source
{
    return Source::create([
        'agent_id' => $agentId,
        'type' => $type,
        'status' => 'indexed',
        'config' => $config,
    ]);
}

dataset('oauth and sql sources', [
    'google_doc → IngestGoogleDocJob' => ['google_doc', ['google_file_id' => 'abc123'], IngestGoogleDocJob::class],
    'google_sheet → SyncGoogleSheetJob' => ['google_sheet', ['google_file_id' => 'sheet123'], SyncGoogleSheetJob::class],
    'notion → IngestNotionPageJob' => ['notion', ['notion_page_id' => 'page123'], IngestNotionPageJob::class],
    'sql → SyncSqlSourceJob' => ['sql', ['driver' => 'mysql'], SyncSqlSourceJob::class],
]);

test('reindex dispatches the type-specific job, never the web crawler', function (string $type, array $config, string $expectedJob) {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = reindexTypeActor();
    $source = makeReindexSource($agent->id, $type, $config);

    $this->actingAs($user)
        ->post("/app/sources/{$source->id}/reindex")
        ->assertRedirect();

    Bus::assertDispatched($expectedJob);
    Bus::assertNotDispatched(CrawlSourceJob::class);

    expect($source->fresh()->status)->toBe('pending');
})->with('oauth and sql sources');

test('reindex still uses CrawlSourceJob for a url source', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = reindexTypeActor();
    $source = makeReindexSource($agent->id, 'url', ['url' => 'https://example.com']);

    $this->actingAs($user)
        ->post("/app/sources/{$source->id}/reindex")
        ->assertRedirect();

    Bus::assertDispatched(CrawlSourceJob::class);
});

test('reindex on a pasted-text source re-indexes from the saved body', function () {
    // Client-reported 2026-07-05: a pasted-text source scarred `failed`
    // by an earlier bug could never be cleared — Reindex was a no-op, yet
    // the scar told the operator to "Click Reindex once to restore it".
    // The body is persisted on the source, so Reindex now re-indexes it.
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = reindexTypeActor();
    $source = makeReindexSource($agent->id, 'text', [
        'title' => 'Pasted note',
        'body' => str_repeat('Storage unit change policy details. ', 5),
        'source_url' => null,
    ]);
    $source->forceFill(['status' => 'failed', 'error' => 'A previous reindex hit a bug.'])->save();

    $this->actingAs($user)
        ->post("/app/sources/{$source->id}/reindex")
        ->assertRedirect();

    Bus::assertDispatched(IndexTextSourceJob::class);
    Bus::assertNotDispatched(CrawlSourceJob::class);
    // Restored: the scar is cleared and it's queued for re-index.
    $fresh = $source->fresh();
    expect($fresh->status)->toBe('pending')
        ->and($fresh->error)->toBeNull()
        ->and(session('success'))->toContain('Re-indexing the saved text');
});

test('reindex on a legacy pasted-text source with no saved body explains how to fix it', function () {
    // Text sources created before the body was persisted have nothing to
    // re-index from — guide the operator to edit or re-add, never a no-op.
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = reindexTypeActor();
    $source = makeReindexSource($agent->id, 'text', ['title' => 'Legacy note']);

    $this->actingAs($user)
        ->post("/app/sources/{$source->id}/reindex")
        ->assertRedirect();

    Bus::assertNothingDispatched();
    expect(session('error'))->toContain('no saved content to re-index')
        // Status left untouched — nothing was queued.
        ->and($source->fresh()->status)->toBe('indexed');
});

test('reindex on an uploaded-file source re-embeds each persisted segment and stays indexed', function () {
    // Second episode of this bug class (client report 2026-07-04):
    // 'file' and 'auto' fell into the default CrawlSourceJob branch,
    // failed with "No URL configured", and flipped healthy sources to
    // failed on every Reindex click.
    Bus::fake();
    Storage::fake(UploadController::DISK);
    ['user' => $user, 'agent' => $agent] = reindexTypeActor();
    $source = makeReindexSource($agent->id, 'file', ['filenames' => ['prices.md']]);

    foreach ([0, 1] as $i) {
        Storage::disk(UploadController::DISK)->put("uploads/{$source->id}/segment-{$i}.txt", "Segment {$i} content for reindexing.");
        Document::factory()->create([
            'source_id' => $source->id,
            'agent_id' => $agent->id,
            'url' => null,
            'title' => "prices.md (segment {$i})",
            'text_path' => "uploads/{$source->id}/segment-{$i}.txt",
        ]);
    }

    $this->actingAs($user)
        ->post("/app/sources/{$source->id}/reindex")
        ->assertRedirect();

    Bus::assertDispatchedTimes(IndexDocumentJob::class, 2);
    Bus::assertNotDispatched(CrawlSourceJob::class);

    $source->refresh();
    expect($source->status)->toBe('indexed')
        ->and($source->error)->toBeNull();
});

test('reindex on a legacy upload without persisted text restores indexed instead of failing', function () {
    Bus::fake();
    Storage::fake(UploadController::DISK);
    ['user' => $user, 'agent' => $agent] = reindexTypeActor();
    $source = makeReindexSource($agent->id, 'file', ['filenames' => ['old.pdf']]);
    // The prod scar: a previous buggy reindex stamped the source failed.
    $source->forceFill(['status' => 'failed', 'error' => 'No URL configured'])->save();

    Document::factory()->create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => null,
        'title' => 'old.pdf',
        'text_path' => null,
    ]);

    $this->actingAs($user)
        ->post("/app/sources/{$source->id}/reindex")
        ->assertRedirect();

    Bus::assertNotDispatched(IndexDocumentJob::class);
    Bus::assertNotDispatched(CrawlSourceJob::class);

    $source->refresh();
    // Content is intact — the honest state is indexed + advice, never failed.
    expect($source->status)->toBe('indexed')
        ->and($source->error)->toBeNull();
    expect(session('warning'))->toContain('re-upload');
});

test('reindex on the auto-indexed source re-crawls each stored page URL', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = reindexTypeActor();
    $source = makeReindexSource($agent->id, 'auto', []);
    $source->forceFill(['status' => 'failed', 'error' => 'No URL configured'])->save();

    foreach (['/contact', '/impressie', '/privacyverklaring'] as $path) {
        Document::factory()->create([
            'source_id' => $source->id,
            'agent_id' => $agent->id,
            'url' => 'https://example.com'.$path,
        ]);
    }
    // A duplicate URL must not double-dispatch.
    Document::factory()->create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => 'https://example.com/contact',
    ]);

    $this->actingAs($user)
        ->post("/app/sources/{$source->id}/reindex")
        ->assertRedirect();

    Bus::assertDispatchedTimes(CrawlPageJob::class, 3);
    Bus::assertNotDispatched(CrawlSourceJob::class);

    $source->refresh();
    expect($source->status)->toBe('crawling')
        ->and($source->error)->toBeNull();
});

test('reindex on an empty auto source explains itself without breaking status', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = reindexTypeActor();
    $source = makeReindexSource($agent->id, 'auto', []);

    $this->actingAs($user)
        ->post("/app/sources/{$source->id}/reindex")
        ->assertRedirect();

    Bus::assertNothingDispatched();
    expect(session('error'))->toContain('as visitors browse');
    expect($source->fresh()->status)->toBe('indexed');
});
