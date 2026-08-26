<?php

use App\Jobs\Analytics\PersistUsageJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageLog;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Support\TokenPricing;

test('PersistUsageJob writes one usage_logs row per upstream call', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
    ]);

    $job = new PersistUsageJob(
        conversationId: $conversation->id,
        agentId: $agent->id,
        messageId: $message->id,
        calls: [
            ['provider' => 'cloudflare', 'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'purpose' => 'chat', 'tokens_in' => 320, 'tokens_out' => 180, 'latency_ms' => 740],
            ['provider' => 'cloudflare', 'model' => '@cf/baai/bge-base-en-v1.5', 'purpose' => 'embed', 'tokens_in' => 60, 'tokens_out' => 0],
        ],
    );
    $job->handle();

    $rows = UsageLog::query()->withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->orderBy('purpose')
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows[0]->purpose)->toBe('chat');
    expect($rows[0]->tokens_in)->toBe(320);
    expect($rows[0]->tokens_out)->toBe(180);
    expect($rows[0]->cost_usd_micro)->toBeGreaterThan(0);
    expect($rows[1]->purpose)->toBe('embed');
});

test('PersistUsageJob is a no-op when the agent does not exist', function () {
    // Agent-missing path returns before the INSERT, so a bogus
    // conversation_id is fine here — no FK row written.
    (new PersistUsageJob(
        conversationId: '019e2000-aaaa-bbbb-cccc-000000000001',
        agentId: '019e2000-ffff-ffff-ffff-000000000099',
        messageId: null,
        calls: [
            ['provider' => 'cloudflare', 'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'purpose' => 'chat', 'tokens_in' => 100, 'tokens_out' => 50],
        ],
    ))->handle();

    expect(UsageLog::query()->withoutWorkspaceScope()->count())->toBe(0);
});

test('Cloudflare Llama 3.3 pricing — 1000 in + 800 out is between 1000 and 2000 micros', function () {
    // 1000 input × 590 micros / 1M = 590 micros
    // 800 output × 790 micros / 1M = 632 micros
    // total ≈ 1222 micros = $0.001222
    $cost = TokenPricing::costMicros(
        'cloudflare',
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        1000,
        800,
    );

    expect($cost)->toBeGreaterThan(1000);
    expect($cost)->toBeLessThan(2000);
});

test('Unknown model falls back to provider wildcard pricing', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    (new PersistUsageJob(
        conversationId: $conversation->id,
        agentId: $agent->id,
        messageId: null,
        calls: [
            ['provider' => 'cloudflare', 'model' => '@cf/experimental/mystery', 'purpose' => 'chat', 'tokens_in' => 50, 'tokens_out' => 50],
        ],
    ))->handle();

    $row = UsageLog::query()->withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tokens_in)->toBe(50);
    // Wildcard ($0.50 in / $0.75 out per 1M) → 50 × 500_000 / 1M + 50 × 750_000 / 1M = 25 + 37 = 62
    expect($row->cost_usd_micro)->toBeGreaterThan(0);
});

test('Unknown provider results in zero cost (no log explosion)', function () {
    expect(TokenPricing::costMicros(
        'mystery-vendor',
        'whatever',
        1000,
        1000,
    ))->toBe(0);
});

test('PersistUsageJob nulls message_id when referenced message is missing', function () {
    // Repro of the production FK violation: PersistTurnJob failed (or
    // message was hard-deleted between dispatch and worker pickup), so
    // the messageId passed in does not resolve. We should still insert
    // the usage row with message_id = NULL instead of throwing
    // SQLSTATE[23000] 1452.
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    $ghostMessageId = '019eaaaa-bbbb-cccc-dddd-eeeeffff0001';

    (new PersistUsageJob(
        conversationId: $conversation->id,
        agentId: $agent->id,
        messageId: $ghostMessageId,
        calls: [
            ['provider' => 'cloudflare', 'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'purpose' => 'chat', 'tokens_in' => 10, 'tokens_out' => 5],
        ],
    ))->handle();

    $row = UsageLog::query()->withoutWorkspaceScope()
        ->where('workspace_id', $workspace->id)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->message_id)->toBeNull();
});
