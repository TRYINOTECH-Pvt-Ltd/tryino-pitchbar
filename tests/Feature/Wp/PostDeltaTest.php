<?php

use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Models\WorkspaceApiToken;
use App\Support\HmacSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function wpDeltaToken(string $workspaceId): string
{
    $plaintext = 'pbar_'.Str::random(48);
    WorkspaceApiToken::create([
        'workspace_id' => $workspaceId,
        'name' => 'wp delta test',
        'token_hash' => hash('sha256', $plaintext),
        'abilities' => ['wp:integration'],
    ]);

    return $plaintext;
}

function deltaPost(int $wpId, string $body, string $hash): array
{
    return [
        'wp_id' => $wpId,
        'post_type' => 'post',
        'permalink' => 'https://shop.example.com/?p='.$wpId,
        'title' => 'Post '.$wpId,
        'content_html' => '<p>'.$body.'</p>',
        'excerpt' => 'Excerpt',
        'content_hash' => $hash,
        'modified_at' => '2026-05-11T00:00:00Z',
        'language' => 'en',
        'taxonomy_terms' => [],
    ];
}

function signedDelta(string $bearer, array $body): TestResponse
{
    $json = json_encode($body, JSON_THROW_ON_ERROR);
    $signature = HmacSignature::sign($bearer, $json);

    return test()->call('POST', '/api/v1/wp/posts/changed', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
        'HTTP_X_PITCHBAR_SIGNATURE' => $signature,
    ], $json);
}

test('upsert action creates and indexes a single post', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpDeltaToken($workspace->id);

    signedDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'action' => 'upsert',
        'post' => deltaPost(100, 'first body', hash('sha256', 'first body')),
    ])
        ->assertOk()
        ->assertJsonPath('data.action', 'upsert')
        ->assertJsonPath('data.result', 'queued');

    expect(Document::query()->withoutWorkspaceScope()->where('external_id', 'wp:100')->count())->toBe(1);
    Queue::assertPushed(IndexDocumentJob::class, 1);
});

test('upsert with same content_hash is a skip, not a re-queue', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpDeltaToken($workspace->id);
    $hash = hash('sha256', 'stable');

    signedDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'action' => 'upsert',
        'post' => deltaPost(11, 'stable', $hash),
    ])->assertOk();

    signedDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'action' => 'upsert',
        'post' => deltaPost(11, 'stable', $hash),
    ])
        ->assertOk()
        ->assertJsonPath('data.result', 'skipped_unchanged');

    Queue::assertPushed(IndexDocumentJob::class, 1);
});

test('delete removes the document and its chunks', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpDeltaToken($workspace->id);

    // Seed a source + document + chunk directly (skipping the queue
    // path) so we can verify the delete cascade cleans up real rows.
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'wordpress',
        'status' => 'indexed',
        'config' => ['site_url' => 'https://shop.example.com'],
    ]);
    $document = Document::create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => 'https://shop.example.com/?p=99',
        'title' => 'Post 99',
        'content_hash' => hash('sha256', 'doomed'),
        'external_id' => 'wp:99',
        'fetched_at' => now(),
    ]);
    Chunk::create([
        'document_id' => $document->id,
        'agent_id' => $agent->id,
        'ord' => 0,
        'text' => 'doomed chunk',
        'token_count' => 3,
    ]);

    signedDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'action' => 'delete',
        'post' => ['wp_id' => 99],
    ])
        ->assertOk()
        ->assertJsonPath('data.action', 'delete')
        ->assertJsonPath('data.deleted', true);

    expect(Document::query()->withoutWorkspaceScope()->where('external_id', 'wp:99')->exists())->toBeFalse();
    expect(Chunk::query()->withoutWorkspaceScope()->where('document_id', $document->id)->exists())->toBeFalse();
});

test('delete of an unknown post is a silent no-op', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpDeltaToken($workspace->id);

    signedDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'action' => 'delete',
        'post' => ['wp_id' => 99999],
    ])
        ->assertOk()
        ->assertJsonPath('data.deleted', false);
});

test('rejects an unknown action value', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpDeltaToken($workspace->id);

    signedDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'action' => 'mutate',
        'post' => ['wp_id' => 1],
    ])->assertStatus(422);
});
