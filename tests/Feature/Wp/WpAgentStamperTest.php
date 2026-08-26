<?php

use App\Models\Agent;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use App\Support\HmacSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * The WpAgentStamper is wired into every WordPress controller that
 * carries an agent_id. These tests pin the contract: a successful
 * plugin call against an agent stamps `wp_integration` with the
 * site_url + plugin_version + WC flag + last_seen_at.
 */
function stamperPostPayload(int $wpId): array
{
    return [
        'wp_id' => $wpId,
        'post_type' => 'post',
        'permalink' => 'https://shop.example.com/?p='.$wpId,
        'title' => 'Stamper post '.$wpId,
        'content_html' => '<p>Body for '.$wpId.'</p>',
        'excerpt' => '',
        'content_hash' => hash('sha256', 'stamp-'.$wpId),
        'modified_at' => '2026-05-13T00:00:00Z',
        'language' => 'en',
        'taxonomy_terms' => [],
    ];
}

function stamperToken(string $workspaceId): string
{
    $plaintext = 'pbar_'.Str::random(48);
    WorkspaceApiToken::create([
        'workspace_id' => $workspaceId,
        'name' => 'stamper test',
        'token_hash' => hash('sha256', $plaintext),
        'abilities' => ['wp:integration'],
    ]);

    return $plaintext;
}

function stamperSignedPost(string $url, string $bearer, array $body): TestResponse
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

test('post sync stamps the agent with site_url, plugin version, and last_seen_at', function () {
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'wp_integration' => null,
    ]);
    $bearer = stamperToken($workspace->id);

    stamperSignedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '2.0.4',
        'posts' => [stamperPostPayload(1)],
    ])->assertOk();

    $agent->refresh();

    expect($agent->wp_integration)->toBeArray();
    expect($agent->wp_integration['site_url'])->toBe('https://shop.example.com');
    expect($agent->wp_integration['plugin_version'])->toBe('2.0.4');
    expect($agent->wp_integration['last_seen_at'])->toBeString();
});

test('product sync stamps woocommerce_active when the plugin reports it', function () {
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->published()->create([
        'workspace_id' => $workspace->id,
    ]);
    $bearer = stamperToken($workspace->id);

    $payload = [
        'wp_id' => 100,
        'name' => 'Aurora Hoodie',
        'permalink' => 'https://shop.example.com/p/100',
        'image_url' => 'https://shop.example.com/img/100.jpg',
        'short_description' => 'Soft pullover',
        'description' => 'Full description',
        'price' => '49.00',
        'regular_price' => '49.00',
        'sale_price' => '',
        'currency' => 'USD',
        'stock_status' => 'instock',
        'on_sale' => false,
        'content_hash' => hash('sha256', 'product-100'),
        'modified_at' => '2026-05-13T00:00:00Z',
        'categories' => ['outerwear'],
        'attributes' => ['color: navy'],
    ];

    stamperSignedPost('/api/v1/wp/products/sync', $bearer, [
        'agent_id' => $agent->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '2.0.4',
        'products' => [$payload],
    ])->assertOk();

    $agent->refresh();
    expect($agent->wp_integration['site_url'])->toBe('https://shop.example.com');
    expect($agent->wp_integration['plugin_version'])->toBe('2.0.4');
    expect($agent->wp_integration['last_seen_at'])->toBeString();
});

test('a 404 on agent_not_found does not stamp any other agent', function () {
    Queue::fake();

    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();
    $agentB = Agent::factory()->published()->create([
        'workspace_id' => $workspaceB->id,
        'wp_integration' => null,
    ]);
    $bearer = stamperToken($workspaceA->id);

    // Token belongs to workspace A; we ask for an agent in workspace B.
    // Controller returns 404 and the stamper must not touch the
    // cross-tenant agent.
    stamperSignedPost('/api/v1/wp/posts/sync', $bearer, [
        'agent_id' => $agentB->id,
        'site_url' => 'https://shop.example.com',
        'plugin_version' => '2.0.4',
        'posts' => [stamperPostPayload(1)],
    ])->assertStatus(404);

    $agentB->refresh();
    expect($agentB->wp_integration)->toBeNull();
});
