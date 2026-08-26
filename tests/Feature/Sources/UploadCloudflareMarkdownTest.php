<?php

use App\Http\Controllers\Admin\UploadController;
use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Cloudflare\ToMarkdownClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery as M;

/**
 * Buyer report: PDF uploads showed "0 chunks". Root cause is that the
 * in-process Smalot/pdfparser is unreliable on Word-exported and
 * scanned-with-text-layer PDFs. The fix here is to route PDF / DOCX
 * / XLSX through Cloudflare's free `toMarkdown` API when CF creds
 * are configured, so we get structured markdown out instead of
 * best-effort plain text. Smalot remains the fallback when no CF
 * keys are present.
 *
 * This test pins:
 *   - When ToMarkdownClient is bound, the upload pipeline calls it
 *     (Smalot is NOT invoked) and produces segments + IndexDocumentJob.
 *   - The CF output is persisted on disk under uploads/{source_id}/
 *     so the existing Reindex flow re-dispatches it without re-fetching.
 *   - When CF throws, the per-file error is recorded on the source
 *     row and the batch still succeeds for other files.
 */
afterEach(fn () => M::close());

function cfUploadActor(): array
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

test('PDF upload routes through Cloudflare toMarkdown when client is bound', function () {
    Bus::fake();
    Storage::fake(UploadController::DISK);
    ['user' => $user, 'agent' => $agent] = cfUploadActor();

    // Pin the CF binding to a mock so we can assert the call and
    // control the markdown output. Without this mock the parser
    // registry would fall back to Smalot.
    $cf = M::mock(ToMarkdownClient::class);
    $cf->shouldReceive('convert')
        ->once()
        ->with(M::type('string'), 'manual.pdf')
        ->andReturn("# Product Manual\n\nDetailed setup instructions go here.\n\n# Troubleshooting\n\nIf the device fails to boot, do X.");
    $this->app->bind(ToMarkdownClient::class, fn () => $cf);

    // Build a tiny but legally-PDF-looking blob. Smalot would CHOKE
    // on this if it were ever called — that's the whole point.
    $pdf = UploadedFile::fake()->createWithContent('manual.pdf', '%PDF-1.4 not-a-real-pdf');

    $this->actingAs($user)->post(
        route('agents.uploads.store', ['agent' => $agent->id]),
        ['files' => [$pdf]],
    )->assertRedirect();

    // Two H1 sections = two segments = two documents.
    $documents = Document::query()->where('agent_id', $agent->id)->orderBy('title')->get();
    expect($documents)->toHaveCount(2);
    expect($documents->pluck('title')->all())
        ->toBe(['manual.pdf', 'manual.pdf (segment 1)']);

    // The markdown is persisted to disk for the reindex path.
    foreach ($documents as $doc) {
        expect($doc->text_path)->not->toBeNull();
        Storage::disk(UploadController::DISK)->assertExists($doc->text_path);
    }

    // The first segment contains the H1 + body, not the second's body.
    $firstText = Storage::disk(UploadController::DISK)->get($documents->first()->text_path);
    expect($firstText)->toContain('Product Manual');
    expect($firstText)->toContain('setup instructions');
    expect($firstText)->not->toContain('Troubleshooting');

    Bus::assertDispatched(IndexDocumentJob::class);
});

test('Cloudflare conversion failure on one file records error and keeps the batch alive', function () {
    Bus::fake();
    Storage::fake(UploadController::DISK);
    ['user' => $user, 'agent' => $agent] = cfUploadActor();

    $cf = M::mock(ToMarkdownClient::class);
    // First file → CF blows up.
    $cf->shouldReceive('convert')
        ->with(M::type('string'), 'broken.pdf')
        ->andThrow(new RuntimeException('toMarkdown rejected broken.pdf: corrupted PDF stream'));
    $this->app->bind(ToMarkdownClient::class, fn () => $cf);

    $bad = UploadedFile::fake()->createWithContent('broken.pdf', '%PDF-broken');
    // .md stays on TextParser regardless of CF binding — proves the
    // sibling file still produces a document.
    $good = UploadedFile::fake()->createWithContent('readme.md', 'Real content the local TextParser will accept.');

    $this->actingAs($user)->post(
        route('agents.uploads.store', ['agent' => $agent->id]),
        ['files' => [$bad, $good]],
    )->assertRedirect();

    expect(Document::query()->where('agent_id', $agent->id)->where('title', 'readme.md')->exists())->toBeTrue();

    $source = Source::query()->where('agent_id', $agent->id)->where('type', 'file')->firstOrFail();
    expect($source->status)->toBe('indexed'); // partial success
    expect($source->error)->toContain('broken.pdf');
    expect($source->error)->toContain('corrupted PDF stream');
});

test('Cloudflare returning empty markdown yields a "no extractable text" warning', function () {
    Bus::fake();
    Storage::fake(UploadController::DISK);
    ['user' => $user, 'agent' => $agent] = cfUploadActor();

    $cf = M::mock(ToMarkdownClient::class);
    $cf->shouldReceive('convert')->once()->andReturn('');
    $this->app->bind(ToMarkdownClient::class, fn () => $cf);

    $pdf = UploadedFile::fake()->createWithContent('empty.pdf', '%PDF-1.4 minimal');

    $this->actingAs($user)->post(
        route('agents.uploads.store', ['agent' => $agent->id]),
        ['files' => [$pdf]],
    )->assertRedirect();

    $source = Source::query()->where('agent_id', $agent->id)->where('type', 'file')->firstOrFail();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('No extractable text');
    expect($source->error)->toContain('empty.pdf');
    Bus::assertNotDispatched(IndexDocumentJob::class);
});

test('without ToMarkdownClient bound, PDF upload still works via Smalot fallback', function () {
    Bus::fake();
    Storage::fake(UploadController::DISK);
    ['user' => $user, 'agent' => $agent] = cfUploadActor();

    // Belt-and-braces: make sure no CF binding leaked in from another
    // test in the file. If the binding is absent the registry returns
    // the in-process PdfParser (Smalot) — which throws on this fake
    // PDF, which UploadController records on source.error, which
    // proves Smalot was the one consulted (not CF).
    $this->app->forgetInstance(ToMarkdownClient::class);

    $pdf = UploadedFile::fake()->createWithContent('fake.pdf', 'NOT-A-PDF');

    $this->actingAs($user)->post(
        route('agents.uploads.store', ['agent' => $agent->id]),
        ['files' => [$pdf]],
    )->assertRedirect();

    $source = Source::query()->where('agent_id', $agent->id)->where('type', 'file')->firstOrFail();
    // Smalot raised on the malformed bytes — recorded as a per-file
    // parser error, NOT a Cloudflare error.
    expect($source->error)->toContain('fake.pdf');
    expect($source->error)->not->toContain('toMarkdown');
});
