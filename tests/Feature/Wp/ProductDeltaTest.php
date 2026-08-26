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

function wcDeltaToken(string $workspaceId): string
{
    $plaintext = 'pbar_'.Str::random(48);
    WorkspaceApiToken::create([
        'workspace_id' => $workspaceId,
        'name' => 'wc delta test',
        'token_hash' => hash('sha256', $plaintext),
        'abilities' => ['wp:integration'],
    ]);

    return $plaintext;
}

function wcDeltaProduct(int $wpId, string $body, string $hash): array
{
    return [
        'wp_id' => $wpId,
        'sku' => 'SKU-'.$wpId,
        'name' => 'Product '.$wpId,
        'permalink' => 'https://shop.example.com/product/'.$wpId,
        'image_url' => 'https://shop.example.com/images/'.$wpId.'.jpg',
        'short_description' => 'Short',
        'description' => $body,
        'price' => '19.99',
        'currency' => 'USD',
        'stock_status' => 'instock',
        'on_sale' => false,
        'content_hash' => $hash,
        'modified_at' => '2026-05-11T00:00:00Z',
        'categories' => [],
        'attributes' => [],
    ];
}

function signedWcDelta(string $bearer, array $body): TestResponse
{
    $json = json_encode($body, JSON_THROW_ON_ERROR);
    $signature = HmacSignature::sign($bearer, $json);

    return test()->call('POST', '/api/v1/wp/products/changed', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
        'HTTP_X_PITCHBAR_SIGNATURE' => $signature,
    ], $json);
}

test('upsert action creates a single product document', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wcDeltaToken($workspace->id);

    signedWcDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'action' => 'upsert',
        'product' => wcDeltaProduct(101, 'first', hash('sha256', 'first')),
    ])
        ->assertOk()
        ->assertJsonPath('data.action', 'upsert')
        ->assertJsonPath('data.result', 'queued');

    expect(Document::query()->withoutWorkspaceScope()->where('external_id', 'wc:101')->count())->toBe(1);
    Queue::assertPushed(IndexDocumentJob::class, 1);
});

test('product upsert with same content_hash is a skip', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wcDeltaToken($workspace->id);
    $hash = hash('sha256', 'stable');

    signedWcDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'action' => 'upsert',
        'product' => wcDeltaProduct(22, 'stable', $hash),
    ])->assertOk();

    signedWcDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'action' => 'upsert',
        'product' => wcDeltaProduct(22, 'stable', $hash),
    ])
        ->assertOk()
        ->assertJsonPath('data.result', 'skipped_unchanged');

    Queue::assertPushed(IndexDocumentJob::class, 1);
});

test('product delete tears down document + chunks', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wcDeltaToken($workspace->id);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'woocommerce_products',
        'status' => 'indexed',
        'config' => ['site_url' => 'https://shop.example.com'],
    ]);
    $document = Document::create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => 'https://shop.example.com/product/99',
        'title' => 'Product 99',
        'content_hash' => hash('sha256', 'doomed'),
        'external_id' => 'wc:99',
        'fetched_at' => now(),
    ]);
    Chunk::create([
        'document_id' => $document->id,
        'agent_id' => $agent->id,
        'ord' => 0,
        'text' => 'doomed',
        'token_count' => 2,
    ]);

    signedWcDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'action' => 'delete',
        'product' => ['wp_id' => 99],
    ])
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(Document::query()->withoutWorkspaceScope()->where('external_id', 'wc:99')->exists())->toBeFalse();
    expect(Chunk::query()->withoutWorkspaceScope()->where('document_id', $document->id)->exists())->toBeFalse();
});

test('product delete of an unknown product is a silent no-op', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wcDeltaToken($workspace->id);

    signedWcDelta($bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'action' => 'delete',
        'product' => ['wp_id' => 99999],
    ])
        ->assertOk()
        ->assertJsonPath('data.deleted', false);
});
