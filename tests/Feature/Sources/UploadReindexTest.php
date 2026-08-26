<?php

use App\Http\Controllers\Admin\UploadController;
use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/**
 * Buyer report: uploaded PDF showed "Indexing didn't finish — 0 chunks"
 * and clicking Reindex did nothing. Root cause was KnowledgeController
 * short-circuiting on `document.url === null` (always true for uploads).
 *
 * Fix:
 *   - UploadController persists each parsed segment to private storage
 *     under uploads/{source_id}/segment-{N}.txt + stamps the path on
 *     document.text_path.
 *   - KnowledgeController::reindex reads that file back + re-dispatches
 *     IndexDocumentJob.
 *
 * Tests below pin both halves of the contract.
 */
function reindexActor(): array
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

test('uploading a file persists each segment text to the private disk + stamps text_path', function () {
    Bus::fake();
    Storage::fake(UploadController::DISK);
    ['user' => $user, 'agent' => $agent] = reindexActor();

    $file = UploadedFile::fake()->createWithContent(
        'readme.md',
        'This is a real markdown body that is long enough to clear the chunker minimum threshold.',
    );

    $this->actingAs($user)->post(
        route('agents.uploads.store', ['agent' => $agent->id]),
        ['files' => [$file]],
    )->assertRedirect();

    $document = Document::query()
        ->where('agent_id', $agent->id)
        ->firstOrFail();

    expect($document->text_path)->not->toBeNull();
    expect($document->text_path)->toStartWith('uploads/');
    Storage::disk(UploadController::DISK)->assertExists($document->text_path);
    expect(Storage::disk(UploadController::DISK)->get($document->text_path))
        ->toContain('real markdown body');
});

test('reindexing an uploaded file re-dispatches IndexDocumentJob with the persisted text', function () {
    Bus::fake();
    Storage::fake(UploadController::DISK);
    ['user' => $user, 'agent' => $agent] = reindexActor();

    // Initial upload — records the segment text.
    $file = UploadedFile::fake()->createWithContent(
        'doc.md',
        'Long enough markdown body to exceed the chunker minimum.',
    );
    $this->actingAs($user)->post(
        route('agents.uploads.store', ['agent' => $agent->id]),
        ['files' => [$file]],
    )->assertRedirect();
    Bus::assertDispatched(IndexDocumentJob::class);

    $document = Document::query()
        ->where('agent_id', $agent->id)
        ->firstOrFail();
    // Reset fake so the reindex dispatch is the only one we observe.
    Bus::fake();

    // Hit the reindex endpoint.
    $this->actingAs($user)
        ->post("/app/documents/{$document->id}/reindex")
        ->assertRedirect();

    Bus::assertDispatched(
        IndexDocumentJob::class,
        function (IndexDocumentJob $job) use ($document) {
            return $job->documentId === $document->id
                && str_contains($job->text, 'Long enough markdown body');
        },
    );

    // Source should be flipped back to 'crawling' and error cleared so
    // the UI removes the stale "indexing didn't finish" banner.
    $source = $document->source()->withoutGlobalScopes()->firstOrFail();
    expect($source->status)->toBe('crawling');
    expect($source->error)->toBeNull();
});

test('reindex shows a clear error when the persisted file is missing', function () {
    Bus::fake();
    Storage::fake(UploadController::DISK);
    ['user' => $user, 'agent' => $agent] = reindexActor();

    // Source + document without persisted text — reindex must surface
    // a clear "re-upload" instruction instead of silently failing.
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'file',
        'status' => 'indexed',
        'config' => ['filenames' => ['old-upload.md']],
    ]);
    $document = Document::create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => null,
        'title' => 'old-upload.md',
        'content_hash' => hash('sha256', 'old'),
        'text_path' => null,
        'lang' => null,
        'fetched_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->post("/app/documents/{$document->id}/reindex");
    $response->assertRedirect();
    expect(session('error'))->toContain('Re-upload');
    Bus::assertNotDispatched(IndexDocumentJob::class);
});

test('Knowledge index payload exposes source_error + reindexable flags', function () {
    Storage::fake(UploadController::DISK);
    ['user' => $user, 'agent' => $agent] = reindexActor();

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'file',
        'status' => 'failed',
        'config' => ['filenames' => ['scanned.pdf']],
        'error' => 'No extractable text: scanned.pdf (likely a scanned PDF with no text layer — OCR not yet supported).',
    ]);
    Document::create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => null,
        'title' => 'scanned.pdf',
        'content_hash' => hash('sha256', 'x'),
        'text_path' => 'uploads/'.$source->id.'/segment-0.txt',
        'lang' => null,
        'fetched_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get(route('agents.knowledge.index', ['agent' => $agent->id]));
    $response->assertOk();

    $rows = collect($response->viewData('page')['props']['documents']['data']);
    $row = $rows->firstWhere('title', 'scanned.pdf');

    expect($row['source_status'])->toBe('failed');
    expect($row['source_error'])->toContain('No extractable text');
    expect($row['reindexable'])->toBeTrue();
});
