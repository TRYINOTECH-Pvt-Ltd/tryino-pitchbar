<?php

use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;

function uploadActor(): array
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

test('admin can upload a markdown file and a document + IndexDocumentJob is created', function () {
    Bus::fake();

    ['user' => $user, 'agent' => $agent] = uploadActor();

    $file = UploadedFile::fake()->createWithContent('docs.md', "# Heading\n\nSome content about the product.\n\n## Another heading\n\nMore content.");

    $this->actingAs($user)->post(route('agents.uploads.store', ['agent' => $agent->id]), [
        'files' => [$file],
    ])->assertRedirect();

    expect(Source::query()->where('agent_id', $agent->id)->where('type', 'file')->exists())->toBeTrue();
    expect(Document::query()->where('agent_id', $agent->id)->count())->toBeGreaterThan(0);
    Bus::assertDispatched(IndexDocumentJob::class);
});

test('uploading an unsupported file format is rejected at the validator (no Source row)', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = uploadActor();

    // .xyz isn't in the `mimes:` allowlist on the upload route, so
    // the validator refuses the upload before the controller runs
    // — no Source row is created, no IndexDocumentJob fires. Pre-
    // hardening, the controller accepted any file and only refused
    // at parser-lookup time. Post-hardening, the rejection happens
    // earlier (cheaper) and the operator gets a 422 with a clear
    // validation message.
    $file = UploadedFile::fake()->createWithContent('mystery.xyz', 'some bytes');

    $this->actingAs($user)
        ->post(route('agents.uploads.store', ['agent' => $agent->id]), ['files' => [$file]])
        ->assertSessionHasErrors('files.0');

    expect(Source::query()->where('agent_id', $agent->id)->where('type', 'file')->exists())->toBeFalse();
    Bus::assertNotDispatched(IndexDocumentJob::class);
});

test('a parser exception on one file does not abort the batch', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = uploadActor();

    // A malformed PDF must not poison sibling files in the same batch.
    $badPdf = UploadedFile::fake()->createWithContent('broken.pdf', 'NOT-A-PDF');
    $goodMd = UploadedFile::fake()->createWithContent('readme.md', 'Real content the parser should accept.');

    $this->actingAs($user)->post(route('agents.uploads.store', ['agent' => $agent->id]), [
        'files' => [$badPdf, $goodMd],
    ])->assertRedirect();

    // The Markdown file produced a document — batch survived.
    expect(Document::query()->where('agent_id', $agent->id)->where('title', 'readme.md')->exists())
        ->toBeTrue();
    // The source's error column records the broken.pdf failure.
    $source = Source::query()->where('agent_id', $agent->id)->where('type', 'file')->firstOrFail();
    expect($source->error)->toContain('broken.pdf');
    // Partial success → status=indexed (we created at least one doc).
    expect($source->status)->toBe('indexed');
});

test('uploading an empty file yields a helpful "no extractable text" error', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = uploadActor();

    $blank = UploadedFile::fake()->createWithContent('blank.md', '');

    $this->actingAs($user)->post(route('agents.uploads.store', ['agent' => $agent->id]), [
        'files' => [$blank],
    ])->assertRedirect();

    $source = Source::query()->where('agent_id', $agent->id)->where('type', 'file')->firstOrFail();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('No extractable text');
    expect($source->error)->toContain('blank.md');
});
