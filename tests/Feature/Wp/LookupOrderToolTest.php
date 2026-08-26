<?php

use App\Models\Conversation;
use App\Models\Source;
use App\Models\Visitor;
use App\Models\WorkspaceApiToken;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\Tools\LookupOrderClient;
use App\Services\Tools\Tools\LookupOrderTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function setupShopperAgent(): array
{
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent([], ['site_type' => 'ecommerce']);
    $token = WorkspaceApiToken::factory()->create([
        'workspace_id' => $workspace->id,
        'shopper_signing_secret' => Str::random(48),
    ]);
    Source::create([
        'agent_id' => $agent->id,
        'type' => 'woocommerce_products',
        'status' => 'indexed',
        'config' => ['site_url' => 'https://shop.example.com'],
        'last_synced_at' => now(),
    ]);

    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'attribution' => ['shopper' => ['wp_user_id' => '101', 'email_hash' => '', 'source' => 'wordpress']],
    ]);

    return ['agent' => $agent, 'conversation' => $conversation, 'token' => $token];
}

test('lookup_order calls the plugin endpoint and returns its data', function () {
    ['agent' => $agent, 'conversation' => $conversation] = setupShopperAgent();

    Http::fake([
        'https://shop.example.com/wp-json/pitchbar/v1/orders/lookup' => Http::response([
            'data' => [
                'orders' => [
                    ['id' => 9001, 'status' => 'completed', 'total' => '49.99'],
                ],
                'count' => 1,
            ],
        ], 200),
    ]);

    $tool = new LookupOrderTool(new LookupOrderClient);
    $out = $tool->execute(['limit' => 5], $agent, ['conversation' => $conversation]);

    expect($out['result']['count'])->toBe(1);
    expect($out['result']['orders'])->toHaveCount(1);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/wp-json/pitchbar/v1/orders/lookup')
            && $request->hasHeader('X-Pitchbar-Signature')
            && str_contains($request->body(), '"wp_user_id":"101"');
    });
});

test('lookup_order returns not_signed_in when conversation has no shopper claim', function () {
    ['agent' => $agent] = setupShopperAgent();
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'attribution' => null,
    ]);

    $tool = new LookupOrderTool(new LookupOrderClient);
    $out = $tool->execute([], $agent, ['conversation' => $conversation]);

    expect($out['result']['error'])->toBe('not_signed_in');
});

test('lookup_order returns no_wordpress_source when the agent has no woocommerce_products source', function () {
    ['workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent([], ['site_type' => 'ecommerce']);
    WorkspaceApiToken::factory()->create([
        'workspace_id' => $workspace->id,
        'shopper_signing_secret' => Str::random(48),
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'attribution' => ['shopper' => ['wp_user_id' => '1', 'email_hash' => '', 'source' => 'wordpress']],
    ]);

    $tool = new LookupOrderTool(new LookupOrderClient);
    $out = $tool->execute([], $agent, ['conversation' => $conversation]);

    expect($out['result']['error'])->toBe('no_wordpress_source');
});

test('lookup_order propagates a plugin 4xx as an error', function () {
    ['agent' => $agent, 'conversation' => $conversation] = setupShopperAgent();

    Http::fake([
        'https://shop.example.com/wp-json/pitchbar/v1/orders/lookup' => Http::response([
            'error' => ['code' => 'signature_mismatch'],
        ], 401),
    ]);

    $tool = new LookupOrderTool(new LookupOrderClient);
    $out = $tool->execute([], $agent, ['conversation' => $conversation]);

    expect($out['result']['error'])->toBe('http_401');
});

test('lookup_order tool reaches the agent when ecommerce preset is active and source exists', function () {
    ['agent' => $agent] = setupShopperAgent();
    $registry = app(ToolRegistry::class);

    $names = collect($registry->forAgent($agent))->map(fn ($t) => $t->name())->all();
    expect($names)->toContain('lookup_order');
});
