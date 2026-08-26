<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function conversationLogFixture(string $agentId, array $messages = [], array $convOverrides = []): Conversation
{
    $visitor = Visitor::factory()->create(['agent_id' => $agentId]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agentId,
        'visitor_id' => $visitor->id,
        ...$convOverrides,
    ]);

    foreach ($messages as $m) {
        DB::table('messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conv->id,
            'role' => $m['role'],
            'content' => $m['content'],
            'citations' => json_encode($m['citations'] ?? []),
            'tokens_in' => 0,
            'tokens_out' => 0,
            'latency_ms' => 0,
            'model' => null,
            'confidence' => $m['confidence'] ?? null,
            'created_at' => $m['at'] ?? now(),
        ]);
    }

    return $conv;
}

test('owner sees the conversations list with totals + per-row aggregates', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);
    conversationLogFixture($agent->id, [
        ['role' => 'user', 'content' => 'what is the price?'],
        ['role' => 'assistant', 'content' => 'It is $999.'],
    ]);
    conversationLogFixture($agent->id, [
        ['role' => 'user', 'content' => 'do you ship internationally?'],
    ], ['started_at' => now()->subDays(2)]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/conversations")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/agents/conversations')
            ->where('totals.conversations', 2)
            ->where('totals.messages', 3)
            ->where('totals.last_24h', 1)
            ->has('conversations', 2)
            ->where(
                'conversations.0.preview',
                fn ($p) => str_contains($p, 'price') || str_contains($p, 'ship'),
            ),
        );
});

test('owner sees a workspace-wide conversations list isolated to their agents', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);
    $secondAgent = Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Support Copilot']);
    $foreign = workspaceMemberWithAgent(['role' => 'owner'], ['name' => 'Foreign Bot']);

    conversationLogFixture($agent->id, [
        ['role' => 'user', 'content' => 'Where are your pricing details?'],
    ]);
    conversationLogFixture($secondAgent->id, [
        ['role' => 'user', 'content' => 'Can I talk to sales?'],
    ]);
    conversationLogFixture($foreign['agent']->id, [
        ['role' => 'user', 'content' => 'This must stay private'],
    ]);

    $this->actingAs($user)
        ->get('/app/conversations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/conversations/index')
            ->where('totals.conversations', 2)
            ->where('totals.active_agents', 2)
            ->where('totals.leads', 0)
            ->where('conversations', fn ($rows) => count($rows) === 2
            && collect($rows)->pluck('agent.name')->filter()->sort()->values()->all() === collect([$agent->name, 'Support Copilot'])->sort()->values()->all()),
        );
});

test('workspace conversations search can match agent names without leaking foreign data', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'owner']);
    $salesAgent = Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Sales Concierge']);
    $supportAgent = Agent::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Support Desk']);
    $foreign = workspaceMemberWithAgent(['role' => 'owner'], ['name' => 'Sales Shadow']);

    conversationLogFixture($salesAgent->id, [['role' => 'user', 'content' => 'Pricing question']]);
    conversationLogFixture($supportAgent->id, [['role' => 'user', 'content' => 'Refund help']]);
    conversationLogFixture($foreign['agent']->id, [['role' => 'user', 'content' => 'Foreign sales note']]);

    $this->actingAs($user)
        ->get('/app/conversations?q=Sales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversations', fn ($rows) => count($rows) === 1
                && $rows[0]['agent']['name'] === 'Sales Concierge'),
        );
});

test('search filters by message content', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);
    conversationLogFixture($agent->id, [['role' => 'user', 'content' => 'tell me about MacBook Air']]);
    conversationLogFixture($agent->id, [['role' => 'user', 'content' => 'what about the iPhone 17?']]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/conversations?q=MacBook")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversations', fn ($rows) => count($rows) === 1
                && str_contains($rows[0]['preview'], 'MacBook')),
        );
});

test('thread view returns every message in chronological order with citations', function () {
    ['user' => $user, 'agent' => $agent] = workspaceMemberWithAgent(['role' => 'owner']);

    $conv = conversationLogFixture($agent->id, [
        ['role' => 'user', 'content' => 'q1', 'at' => now()->subMinutes(5)],
        ['role' => 'assistant', 'content' => 'a1', 'citations' => [['id' => 1, 'url' => 'https://x.com/a']], 'at' => now()->subMinutes(4)],
        ['role' => 'user', 'content' => 'q2', 'at' => now()->subMinutes(3)],
    ]);

    $this->actingAs($user)
        ->get("/app/conversations/{$conv->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/conversations/show')
            ->has('messages', 3)
            ->where('messages.0.content', 'q1')
            ->where('messages.1.content', 'a1')
            ->where('messages.1.citations.0.url', 'https://x.com/a')
            ->where('messages.2.content', 'q2'),
        );
});

test('cross-workspace conversation access is forbidden', function () {
    ['user' => $user] = workspaceMember(['role' => 'owner']);
    $other = workspaceMemberWithAgent(['role' => 'owner']);
    $conv = conversationLogFixture($other['agent']->id);

    $this->actingAs($user)
        ->get("/app/agents/{$other['agent']->id}/conversations")
        ->assertForbidden();

    $this->actingAs($user)
        ->get("/app/conversations/{$conv->id}")
        ->assertForbidden();
});
