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

function wcTokenFor(string $workspaceId): string
{
    $plaintext = 'pbar_'.Str::random(48);
    WorkspaceApiToken::create([
        'workspace_id' => $workspaceId,
        'name' => 'wc test',
        'token_hash' => hash('sha256', $plaintext),
        'abilities' => ['wp:integration'],
    ]);

    return $plaintext;
}

function productPayload(int $wpId, string $body, ?string $hash = null): array
{
    return [
        'wp_id' => $wpId,
        'sku' => 'SKU-'.$wpId,
        'name' => 'Product '.$wpId,
        'permalink' => 'https://shop.example.com/product/'.$wpId,
        'image_url' => 'https://shop.example.com/images/'.$wpId.'.jpg',
        'short_description' => 'Short for '.$wpId,
        'description' => '<p>'.$body.'</p>',
        'price' => '29.99',
        'regular_price' => '39.99',
        'sale_price' => '29.99',
        'currency' => 'USD',
        'stock_status' => 'instock',
        'on_sale' => true,
        'content_hash' => $hash ?? hash('sha256', $body),
        'modified_at' => '2026-05-11T00:00:00Z',
        'categories' => ['shirts'],
        'attributes' => ['color: blue', 'size: M'],
    ];
}

function signedProductPost(string $url, string $bearer, array $body): TestResponse
{
    $json = json_encode($body, JSON_THROW_ON_ERROR);
    $signature = HmacSignature::sign($bearer, $json);

    return test()->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
        'HTTP_X_PITCHBAR_SIGNATURE' => $signature,
    ], $json);
}

test('bulk product sync creates documents and queues IndexDocumentJob for each', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wcTokenFor($workspace->id);

    signedProductPost('/api/v1/wp/products/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'products' => [
            productPayload(1, 'blue shirt'),
            productPayload(2, 'red shirt'),
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.accepted', 2)
        ->assertJsonPath('data.queued', 2)
        ->assertJsonPath('data.skipped_unchanged', 0);

    $docs = Document::query()->withoutWorkspaceScope()->where('agent_id', $agent->id)->get();
    expect($docs->pluck('external_id')->sort()->values()->all())->toBe(['wc:1', 'wc:2']);

    Queue::assertPushed(IndexDocumentJob::class, 2);
});

test('product sync auto-switches a generic agent to ecommerce site_type', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent([], ['site_type' => 'generic']);
    expect($agent->site_type)->toBe('generic');

    $bearer = wcTokenFor($workspace->id);

    signedProductPost('/api/v1/wp/products/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'products' => [productPayload(1, 'body')],
    ])->assertOk();

    expect($agent->refresh()->site_type)->toBe('ecommerce');
});

test('product sync does NOT overwrite an explicitly-set site_type', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent([], ['site_type' => 'saas']);
    $bearer = wcTokenFor($workspace->id);

    signedProductPost('/api/v1/wp/products/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'products' => [productPayload(1, 'body')],
    ])->assertOk();

    expect($agent->refresh()->site_type)->toBe('saas');
});

test('product sync skips unchanged products on re-POST', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wcTokenFor($workspace->id);

    $body = [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'products' => [productPayload(42, 'stable')],
    ];

    signedProductPost('/api/v1/wp/products/sync', $bearer, $body)->assertOk();
    Queue::assertPushed(IndexDocumentJob::class, 1);

    signedProductPost('/api/v1/wp/products/sync', $bearer, $body)
        ->assertOk()
        ->assertJsonPath('data.queued', 0)
        ->assertJsonPath('data.skipped_unchanged', 1);

    Queue::assertPushed(IndexDocumentJob::class, 1);
});

test('product sync creates one woocommerce_products Source per site', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wcTokenFor($workspace->id);

    signedProductPost('/api/v1/wp/products/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'products' => [productPayload(1, 'a')],
    ])->assertOk();

    signedProductPost('/api/v1/wp/products/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com/',
        'plugin_version' => '1.0.2',
        'products' => [productPayload(2, 'b')],
    ])->assertOk();

    $sources = Source::query()
        ->withoutWorkspaceScope()
        ->where('agent_id', $agent->id)
        ->where('type', 'woocommerce_products')
        ->get();
    expect($sources)->toHaveCount(1);
    expect($sources->first()->config['plugin_version'])->toBe('1.0.2');
});

test('product sync rejects an oversize batch', function () {
    Queue::fake();
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wcTokenFor($workspace->id);

    $products = [];
    for ($i = 0; $i < 51; $i++) {
        $products[] = productPayload($i, 'body '.$i);
    }

    signedProductPost('/api/v1/wp/products/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'products' => $products,
    ])->assertStatus(422);

    Queue::assertNothingPushed();
});

test('product sync rejects a missing HMAC signature', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent();
    $bearer = wcTokenFor($workspace->id);

    $this->postJson('/api/v1/wp/products/sync', [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'products' => [productPayload(1, 'body')],
    ], ['Authorization' => 'Bearer '.$bearer])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'missing_signature');
});

test('product sync rejects a foreign agent id', function () {
    Queue::fake();
    ['workspace' => $workspaceA, 'agent' => $agentA] = workspaceMemberWithAgent();
    ['agent' => $agentB] = workspaceMemberWithAgent();
    $bearer = wcTokenFor($workspaceA->id);

    signedProductPost('/api/v1/wp/products/sync', $bearer, [
        'agent_id' => $agentB->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '1.0.1',
        'products' => [productPayload(1, 'body')],
    ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'agent_not_found');

    Queue::assertNothingPushed();
});
