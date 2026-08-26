<?php

use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Document;
use App\Models\Source;
use App\Models\WorkspaceApiToken;
use App\Support\HmacSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function wpTokenFor(string $workspaceId): string
{
    $plaintext = 'pbar_'.Str::random(48);
    WorkspaceApiToken::create([
        'workspace_id' => $workspaceId,
        'name' => 'wp test',
        'token_hash' => hash('sha256', $plaintext),
        'abilities' => ['wp:integration'],
    ]);

    return $plaintext;
}

function postPayload(int $wpId, string $body, ?string $hash = null): array
{
    return [
        'wp_id' => $wpId,
        'post_type' => 'post',
        'permalink' => 'https://shop.example.com/?p='.$wpId,
        'title' => 'Post '.$wpId,
        'content_html' => '<p>'.$body.'</p>',
        'excerpt' => 'Excerpt for '.$wpId,
        'content_hash' => $hash ?? hash('sha256', $body),
        'modified_at' => '2026-05-11T00:00:00Z',
        'language' => 'en',
        'taxonomy_terms' => ['news'],
    ];
}

function signedPost(string $url, string $bearer, array $body): TestResponse
{
    $json = json_encode($body, JSON_THROW_ON_ERROR);
    $signature = HmacSignature::sign($bearer, $json);

    return test()->call(
        'POST',
        $url,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
            'HTTP_X_PITCHBAR_SIGNATURE' => $signature,
        ],
        $json,
    );
}

test('bulk sync accepts a batch with one empty content_html post (regression for buyer report)', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpTokenFor($workspace->id);

    // Buyer hit "Batch 1 failed: posts.2.content_html field is required."
    // because the 3rd post had no body content — WP often produces those
    // for featured-image-only or page-builder posts where post_content
    // is empty. Server validation now permits empty strings and the
    // ingest service downgrades empty-content posts to title-only
    // (queued_empty, no chunks but the Document still exists).
    $emptyPost = postPayload(3, '');
    $emptyPost['content_html'] = '';
    $emptyPost['content_hash'] = hash('sha256', '');

    $response = signedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => [
            postPayload(1, 'real body one'),
            postPayload(2, 'real body two'),
            $emptyPost,
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.accepted', 3);
    // Even the empty-content post indexes via title + excerpt + terms.
    expect(Document::query()->withoutWorkspaceScope()->where('agent_id', $agent->id)->count())->toBe(3);
    Queue::assertPushed(IndexDocumentJob::class, 3);
});

test('bulk sync creates documents and queues IndexDocumentJob for each', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpTokenFor($workspace->id);

    $response = signedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => [
            postPayload(1, 'first post body'),
            postPayload(2, 'second post body'),
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.accepted', 2)
        ->assertJsonPath('data.queued', 2)
        ->assertJsonPath('data.skipped_unchanged', 0);

    $docs = Document::query()->withoutWorkspaceScope()->where('agent_id', $agent->id)->get();
    expect($docs)->toHaveCount(2);
    expect($docs->pluck('external_id')->sort()->values()->all())->toBe(['wp:1', 'wp:2']);

    Queue::assertPushed(IndexDocumentJob::class, 2);
});

test('bulk sync skips unchanged posts on re-POST', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpTokenFor($workspace->id);

    $payload = [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => [postPayload(42, 'stable body')],
    ];

    signedPost('/api/v1/wp/posts/sync', $bearer, $payload)->assertOk();
    Queue::assertPushed(IndexDocumentJob::class, 1);

    // Re-POST with the same content_hash — should skip.
    signedPost('/api/v1/wp/posts/sync', $bearer, $payload)
        ->assertOk()
        ->assertJsonPath('data.queued', 0)
        ->assertJsonPath('data.skipped_unchanged', 1);

    // Still only one job total (no second dispatch).
    Queue::assertPushed(IndexDocumentJob::class, 1);
    expect(Document::query()->withoutWorkspaceScope()->where('agent_id', $agent->id)->count())->toBe(1);
});

test('bulk sync upserts an existing document when content_hash changes', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpTokenFor($workspace->id);

    signedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => [postPayload(7, 'original body')],
    ])->assertOk();

    signedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => [postPayload(7, 'updated body')],
    ])
        ->assertOk()
        ->assertJsonPath('data.queued', 1);

    $doc = Document::query()
        ->withoutWorkspaceScope()
        ->where('agent_id', $agent->id)
        ->where('external_id', 'wp:7')
        ->sole();
    expect($doc->content_hash)->toBe(hash('sha256', 'updated body'));

    Queue::assertPushed(IndexDocumentJob::class, 2);
});

test('bulk sync rejects an oversize batch', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpTokenFor($workspace->id);

    $posts = [];
    for ($i = 0; $i < 51; $i++) {
        $posts[] = postPayload($i, 'body '.$i);
    }

    signedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => $posts,
    ])->assertStatus(422);

    Queue::assertNothingPushed();
});

test('bulk sync rejects a missing or invalid HMAC signature', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpTokenFor($workspace->id);

    $body = [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => [postPayload(1, 'body')],
    ];

    // Missing signature header entirely
    $this->postJson('/api/v1/wp/posts/sync', $body, ['Authorization' => 'Bearer '.$bearer])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'missing_signature');

    // Wrong signature (signed against a different body)
    $wrongSig = HmacSignature::sign($bearer, 'tampered');
    $this->postJson('/api/v1/wp/posts/sync', $body, [
        'Authorization' => 'Bearer '.$bearer,
        'X-Pitchbar-Signature' => $wrongSig,
    ])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'signature_mismatch');
});

test('bulk sync rejects an expired (replayed) signature', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpTokenFor($workspace->id);

    $body = [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => [postPayload(1, 'body')],
    ];
    $json = json_encode($body, JSON_THROW_ON_ERROR);

    $oldTimestamp = time() - HmacSignature::REPLAY_WINDOW_SECONDS - 60;
    $expiredSig = HmacSignature::sign($bearer, $json, $oldTimestamp);

    $this->call('POST', '/api/v1/wp/posts/sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
        'HTTP_X_PITCHBAR_SIGNATURE' => $expiredSig,
    ], $json)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'signature_mismatch');
});

test('bulk sync rejects a foreign agent id', function () {
    Queue::fake();
    ['workspace' => $workspaceA, 'agent' => $agentA] = workspaceMemberWithAgent();
    ['agent' => $agentB] = workspaceMemberWithAgent();

    $bearer = wpTokenFor($workspaceA->id);

    signedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agentB->id, // belongs to a different workspace
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => [postPayload(1, 'body')],
    ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'agent_not_found');

    Queue::assertNothingPushed();
});

test('bulk sync creates exactly one source row per (agent, site host)', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpTokenFor($workspace->id);

    signedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => [postPayload(1, 'body')],
    ])->assertOk();

    signedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com/',
        'plugin_version' => '1.0.1',
        'posts' => [postPayload(2, 'body 2')],
    ])->assertOk();

    $sources = Source::query()
        ->withoutWorkspaceScope()
        ->where('agent_id', $agent->id)
        ->where('type', 'wordpress')
        ->get();
    expect($sources)->toHaveCount(1);
    expect($sources->first()->config['plugin_version'])->toBe('1.0.1');
});

test('bulk sync stamps the source last_synced_at on success', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wpTokenFor($workspace->id);

    signedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.0',
        'posts' => [postPayload(1, 'body')],
    ])->assertOk();

    $source = Source::query()->withoutWorkspaceScope()->where('agent_id', $agent->id)->sole();
    expect($source->last_synced_at)->not()->toBeNull();
    expect($source->status)->toBe('indexed');
});
